const MONTHS = ["Янв", "Фев", "Мар", "Апр", "Май", "Июн", "Июл", "Авг", "Сен", "Окт", "Ноя", "Дек"]; // Сокращенные названия для шапки
const FULL_MONTHS = ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"]; // Полные для попапов
const MOOD_COLORS = {
    'great': { label: 'Классный день', color: '#1dd1a1' },
    'good': { label: 'Хороший день', color: '#54a0ff' },
    'ok': { label: 'Обычный день', color: '#ffda79' },
    'sad': { label: 'Грустный день', color: '#ffb142' },
    'angry': { label: 'Злой день', color: '#ff5252' },
    'awful': { label: 'Отвратительный день', color: '#747d8c' },
    'tired': { label: 'Усталый день', color: '#9c88ff' },
    'sick': { label: 'Болезнь', color: '#ff793f' },
};
const MOOD_ACTIVITY = {
    'alcohol': { emoji: '🍷' },
    'sport': { emoji: '💪🏻' },
    'sex': { emoji: '🍓' },
    'friends': { emoji: '🤟🏻' },
    'romantic': { emoji: '💕' },
    'crying': { emoji: '😭' },
    'WomanDay': { emoji: '🩸' },
};

const MIN_SHARE_WIDTH = 640; // Минимальная ширина скриншота

// --- DOM Elements ---
const calendarGrid = document.getElementById('calendarGrid');
const monthsHeader = document.getElementById('monthsHeader');
const daysAxis = document.getElementById('daysAxis');
const currentYearDisplay = document.getElementById('currentYearDisplay');
const prevYearBtn = document.getElementById('prevYearBtn');
const nextYearBtn = document.getElementById('nextYearBtn');
const editPopup = document.getElementById('editPopup');
const editPopupHeader = document.getElementById('editPopupHeader');
const popupColorsContainer = document.getElementById('popupColors');
const popupColorsDescr = document.getElementById('popupColorsDescr');
const dayDescriptionInput = document.getElementById('dayDescription');
const charCounter = document.getElementById('charCounter');
const saveDayBtn = document.getElementById('saveDayBtn');
const closeEditPopupBtn = document.getElementById('closeEditPopupBtn');
const cancelEditBtn = document.getElementById('cancelEditBtn');
const viewPopup = document.getElementById('viewPopup');
const viewPopupHeader = document.getElementById('viewPopupHeader');
const viewPopupColor = document.getElementById('viewPopupColor');
const viewPopupMood = document.getElementById('viewPopupMood');
const viewPopupDescription = document.getElementById('viewPopupDescription');
const editDayBtn = document.getElementById('editDayBtn');
const closeViewPopupBtn = document.getElementById('closeViewPopupBtn');
const cancelViewBtn = document.getElementById('cancelViewBtn');
const shareButton = document.getElementById('shareButton');
const settingsButton = document.getElementById('settingsButton');
const settingsPanel = document.getElementById('settingsPanel');
const closeSettingsBtn = document.getElementById('closeSettingsBtn');
const themeOptions = document.getElementById('themeOptions');
const layoutOptions = document.getElementById('layoutOptions');
const backendOptions = document.getElementById('backendOptions');

const editAlcoholCheckbox = document.getElementById('editAlcohol');
const editSportCheckbox = document.getElementById('editSport');
const editSexCheckbox = document.getElementById('editSex');
const editFriendsCheckbox = document.getElementById('editFriends');
const editRomanticCheckbox = document.getElementById('editRomantic');
const editCryingCheckbox = document.getElementById('editCrying');
const editWomanDayCheckbox = document.getElementById('editWomanDay');
const viewAlcoholCheckbox = document.getElementById('viewAlcohol');
const viewSportCheckbox = document.getElementById('viewSport');
const viewSexCheckbox = document.getElementById('viewSex');
const viewFriendsCheckbox = document.getElementById('viewFriends');
const viewRomanticCheckbox = document.getElementById('viewRomantic');
const viewCryingCheckbox = document.getElementById('viewCrying');
const viewWomanDayCheckbox = document.getElementById('viewWomanDay');

const legendContainer = document.getElementById('legend');

const appBody = document.body;
const appContainer = document.querySelector('.app');

// --- State ---
let currentYear = new Date().getFullYear();
const ACTUAL_CURRENT_YEAR = new Date().getFullYear();
let calendarData = {}; // { year: { month: { day: { color: 'mood_key', description: 'text' } } } }
let availableYearsData = []; // Массив доступных лет из API
let selectedDate = null; // YYYY-MM-DD
let selectedColorKey = null;
let currentUserId = null; // Telegram User ID
let isLoading = false; // Флаг для индикации загрузки
let originalLayoutClass = null; // Для хранения исходного класса layout

// --- Telegram WebApp ---
const tg = window.Telegram.WebApp;
let initData = null; // Переменная для хранения initData

// --- Utility Functions ---
const getDaysInMonth = (year, month) => new Date(year, month + 1, 0).getDate();
const formatDate = (year, month, day) => `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
const parseDate = (dateString) => {
    const [year, month, day] = dateString.split('-').map(Number);
    return { year, month: month - 1, day }; // month is 0-indexed internally
};

/**
 * Скрывает элементы с классом 'uncapturable' перед скриншотом.
 * @returns {Function} Функция для восстановления видимости.
 */
const hideUncapturableElements = () => {
    const uncapturableElements = document.querySelectorAll('.uncapturable');
    const originalDisplay = [];

    uncapturableElements.forEach((el, index) => {
        originalDisplay[index] = el.style.display;
        el.style.display = 'none';
    });

    return () => {
        uncapturableElements.forEach((el, index) => {
            el.style.display = originalDisplay[index] || '';
        });
    };
};

/**
 * Делает скриншот указанных элементов.
 * @param {HTMLElement[]} elements Массив элементов для скриншота.
 * @param {number} minWidth Минимальная ширина результирующего изображения.
 * @returns {Promise<Blob>} Promise, который разрешается в Blob изображения JPEG.
 */
const captureElementsAsImage = async (elements, minWidth) => {
    // Несмотря на название функции, мы будем захватывать только body целиком
    // Это проще и надежнее для получения полного скриншота с правильными пропорциями
    console.log("captureElementsAsImage: Запуск захвата body. Минимальная ширина:", minWidth);
    
    if (typeof html2canvas === 'undefined') {
        throw new Error('Библиотека html2canvas не найдена.');
    }

    const restoreVisibility = hideUncapturableElements();

    // Сохраняем оригинальные стили body
    const originalBodyWidth = appBody.style.width;
    const originalBodyMinWidth = appBody.style.minWidth;
    const originalBodyOverflowX = appBody.style.overflowX;
    
    // Сохраняем и меняем layout класс
    const layoutClasses = ['app--layout-full', 'app--layout-half', 'app--layout-compact', 'app--layout-fill'];
    const currentLayoutClasses = [...appBody.classList].filter(cls => layoutClasses.includes(cls));
    originalLayoutClass = currentLayoutClasses[0] || 'app--layout-full';
    appBody.classList.remove(...layoutClasses);
    appBody.classList.add('app--layout-full');
    
    // Удаляем потенциальные ограничения ширины со .app для скриншота
    const appElement = document.querySelector('.app');
    const originalAppMaxWidth = appElement ? appElement.style.maxWidth : '';
    if (appElement) {
        appElement.style.maxWidth = 'none'; // Убираем ограничение ширины
    }

    try {
        console.log("captureElementsAsImage: Установка временной ширины для body.");
        // --- ВАЖНО: Устанавливаем фиксированную минимальную ширину для body перед скриншотом ---
        // Это должно заставить layout пересчитаться и html2canvas захватить правильную область
        appBody.style.width = `${minWidth}px`;
        appBody.style.minWidth = `${minWidth}px`;
        appBody.style.overflowX = 'hidden'; // На всякий случай скрываем горизонтальный скролл
        
        // Принудительная перерисовка браузера
        await new Promise(resolve => requestAnimationFrame(resolve));
        await new Promise(resolve => requestAnimationFrame(resolve));

        console.log("captureElementsAsImage: Вызов html2canvas для document.body");
        const canvas = await html2canvas(document.body, {
            backgroundColor: getComputedStyle(document.documentElement)
                .getPropertyValue('--color-bg')
                .trim() || getComputedStyle(document.body).backgroundColor || '#ffffff',
            scale: 2, // Увеличиваем качество
            useCORS: true,
            logging: false, // Поставить true для отладки
            // width, height, x, y НЕ указываем, пусть захватит всё тело с новыми размерами
        });

        console.log("captureElementsAsImage: html2canvas завершен. Ширина canvas:", canvas.width, "Высота:", canvas.height);
        
        // Проверка, что canvas не пустой
        if (canvas.width === 0 || canvas.height === 0) {
             throw new Error("Финальный canvas имеет нулевой размер.");
        }

        return new Promise((resolve, reject) => {
            console.log("captureElementsAsImage: Создание Blob из canvas...");
            const trimmedCanvas = trimCanvas(canvas);
            trimmedCanvas.toBlob((blob) => {
                if (blob) {
                    console.log("captureElementsAsImage: Blob успешно создан.");
                    resolve(blob);
                } else {
                    console.error("captureElementsAsImage: Не удалось создать Blob из canvas");
                    reject(new Error("Не удалось создать изображение (Blob пуст)"));
                }
            }, 'image/jpeg', 0.92);
        });

    } finally {
        console.log("captureElementsAsImage: Восстановление исходных стилей и классов.");
        // --- Восстанавливаем оригинальные стили body ---
        appBody.style.width = originalBodyWidth || '';
        appBody.style.minWidth = originalBodyMinWidth || '';
        appBody.style.overflowX = originalBodyOverflowX || '';
        
        // Восстанавливаем layout класс
        appBody.classList.remove('app--layout-full');
        if (originalLayoutClass && layoutClasses.includes(originalLayoutClass)) {
            appBody.classList.add(originalLayoutClass);
        }
        
        // Восстанавливаем стиль .app
        if (appElement) {
            appElement.style.maxWidth = originalAppMaxWidth;
        }
        
        restoreVisibility();
        console.log("captureElementsAsImage: Восстановление завершено.");
    }
};

function trimCanvasBottom(canvas) {
    const ctx = canvas.getContext('2d');
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;

    let lastNonEmptyRow = canvas.height - 1;
    const rowLength = canvas.width * 4;

    for (let y = canvas.height - 1; y >= 0; y--) {
        let isEmpty = true;
        for (let x = 0; x < rowLength; x += 4) {
            // Проверяем, что пиксель не белый (255,255,255) и не прозрачный
            const idx = y * rowLength + x;
            if (!(data[idx] === 255 && data[idx + 1] === 255 && data[idx + 2] === 255 && data[idx + 3] === 255)) {
                isEmpty = false;
                break;
            }
        }
        if (!isEmpty) {
            lastNonEmptyRow = y;
            break;
        }
    }

    const trimmedHeight = lastNonEmptyRow + 1;
    if (trimmedHeight < canvas.height) {
        const trimmedCanvas = document.createElement('canvas');
        trimmedCanvas.width = canvas.width;
        trimmedCanvas.height = trimmedHeight;
        trimmedCanvas.getContext('2d').drawImage(canvas, 0, 0);
        return trimmedCanvas;
    }
    return canvas;
}

/**
 * Обрезает прозрачные или белые области с краев canvas.
 * @param {HTMLCanvasElement} canvas Исходный canvas.
 * @param {string} [backgroundColor='rgba(255, 255, 255, 0)'] Цвет, который считается "пустым".
 *                                     По умолчанию - белый или прозрачный.
 * @returns {HTMLCanvasElement} Новый обрезанный canvas или оригинальный, если обрезка не нужна.
 */
function trimCanvas(canvas, backgroundColor = 'rgba(255, 255, 255, 0)') {
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;

    // Если canvas пуст, возвращаем его как есть
    if (width === 0 || height === 0) {
        return canvas;
    }

    const imageData = ctx.getImageData(0, 0, width, height);
    const data = imageData.data;

    // Парсим цвет фона для сравнения
    let emptyPixel = { r: 255, g: 255, b: 255, a: 0 }; // По умолчанию
    if (backgroundColor === 'rgba(255, 255, 255, 0)') {
        // Уже установлено по умолчанию
    } else if (backgroundColor.startsWith('rgba(')) {
        const rgba = backgroundColor.match(/\d+/g);
        if (rgba && rgba.length >= 4) {
            emptyPixel = { r: parseInt(rgba[0]), g: parseInt(rgba[1]), b: parseInt(rgba[2]), a: parseInt(rgba[3]) * 255 };
        }
    } else if (backgroundColor.startsWith('rgb(')) {
        const rgb = backgroundColor.match(/\d+/g);
        if (rgb && rgb.length >= 3) {
            emptyPixel = { r: parseInt(rgb[0]), g: parseInt(rgb[1]), b: parseInt(rgb[2]), a: 255 };
        }
    }
    // Для простоты можно оставить только проверку на белый+прозрачный

    let minX = width;
    let minY = height;
    let maxX = -1;
    let maxY = -1;

    const rowLength = width * 4;

    // Проходим по всем пикселям
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const idx = (y * rowLength) + (x * 4);
            const r = data[idx];
            const g = data[idx + 1];
            const b = data[idx + 2];
            const a = data[idx + 3];

            // Проверяем, является ли пиксель "пустым"
            // Упрощенная проверка: белый и полностью прозрачный ИЛИ полностью белый и непрозрачный (как предыдущая)
            // Более точная проверка: if (!(r === emptyPixel.r && g === emptyPixel.g && b === emptyPixel.b && a === emptyPixel.a))
            if (!(r === 255 && g === 255 && b === 255 && a === 255)) { // Используем старую проверку для совместимости
                // Пиксель не пустой, обновляем границы
                minX = Math.min(minX, x);
                minY = Math.min(minY, y);
                maxX = Math.max(maxX, x);
                maxY = Math.max(maxY, y);
            }
        }
    }

    // Если не было найдено ни одного непустого пикселя
    if (minX > maxX || minY > maxY) {
        console.warn("Canvas полностью пустой или состоит только из 'пустых' пикселей.");
        // Можно вернуть canvas 1x1 пиксель или оригинальный
        // Возвращаем оригинальный, как и делала старая функция в случае полной пустоты
        return canvas;
    }

    // Рассчитываем размеры нового canvas
    const trimmedWidth = maxX - minX + 1;
    const trimmedHeight = maxY - minY + 1;

    // Если размеры не изменились, возвращаем оригинальный canvas
    if (trimmedWidth === width && trimmedHeight === height) {
        return canvas;
    }

    // Создаем новый canvas с обрезанными размерами
    const trimmedCanvas = document.createElement('canvas');
    trimmedCanvas.width = trimmedWidth;
    trimmedCanvas.height = trimmedHeight;

    // Рисуем обрезанную часть на новый canvas
    trimmedCanvas.getContext('2d').drawImage(
        canvas,
        minX, minY, trimmedWidth, trimmedHeight, // источник (область на оригинальном canvas)
        0, 0, trimmedWidth, trimmedHeight        // назначение (на весь новый canvas)
    );

    return trimmedCanvas;
}

/**
 * Отправляет Blob изображения на бэкенд.
 * @param {Blob} blob Blob изображения.
 * @param {number} userId Telegram User ID.
 * @param {string} initData Данные инициализации Telegram.
 */
const sendImageToBackend = async (blob, userId, initData) => {
    if (!userId) {
        const errorMsg = 'User ID не определен. Невозможно отправить изображение.';
        console.error(errorMsg);
        tg.showAlert(errorMsg);
        return;
    }
    if (!initData) {
        const errorMsg = 'initData не определен. Невозможно отправить изображение.';
        console.error(errorMsg);
        tg.showAlert(errorMsg);
        return;
    }

    try {
        console.log("Начинаем отправку изображения на бэкенд...");

        // Показываем индикатор загрузки, так как преобразование и отправка могут занять время
        showLoading(true);
        tg.MainButton?.showProgress(); 

        // 1. Преобразуем Blob в Base64
        const base64Image = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onloadend = () => {
                // reader.result содержит строку данных URL, например: "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQ..."
                // Нам нужна только часть после запятой
                const base64String = reader.result.split(',')[1];
                if (base64String) {
                    resolve(base64String);
                } else {
                    reject(new Error('Не удалось преобразовать Blob в Base64.'));
                }
            };
            reader.onerror = reject;
            reader.readAsDataURL(blob); // Читаем Blob как Data URL
        });

        console.log("Blob успешно преобразован в Base64. Длина строки:", base64Image.length);

        // 2. Подготавливаем данные для отправки
        const payload = {
            mode: "saveImage",
            user_id: userId, // Используем полученный userId
            image: base64Image
        };

        console.log("Отправка данных на бэкенд:", API_URL, payload);

        // 3. Отправляем POST запрос
        const response = await fetch(API_URL, { // Используем глобальную переменную API_URL
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Authorization': initData, // Передаем initData в headers для аутентификации
            },
            body: JSON.stringify(payload)
        });

        console.log("Ответ от бэкенда получен:", response.status, response.statusText);

        if (!response.ok) {
            // Попробуем получить текст ошибки от сервера
            let errorText = '';
            try {
                const errorResult = await response.json();
                errorText = errorResult.message || errorResult.error || response.statusText;
            } catch (e) {
                errorText = `${response.status} ${response.statusText}`;
            }
            throw new Error(`Ошибка сети или сервера: ${errorText}`);
        }

        const result = await response.json();
        console.log("Успешный ответ от бэкенда:", result);

        if (result.success) {
            console.log('Изображение успешно отправлено на бэкенд.');
            tg.showAlert('Скриншот отправлен! Бот пришлет его вам в чат.');
        } else {
            const errorMsg = `Ошибка API: ${result.message || 'Неизвестная ошибка при отправке изображения.'}`;
            throw new Error(errorMsg);
        }

    } catch (error) {
        console.error('Ошибка при отправке изображения на бэкенд:', error);
        tg.showAlert('Ошибка при отправке скриншота: ' + error.message);
    } finally {
        // Всегда скрываем индикатор загрузки
        showLoading(false);
        tg.MainButton?.hideProgress();
    }
};


// --- Loading Indicator ---
const showLoading = (show) => {
    isLoading = show;
    // Можно добавить/удалить класс на body или показывать/скрывать spinner
    appBody.classList.toggle('app--loading', show);
    // Блокируем кнопки во время загрузки
    prevYearBtn.disabled = show || (currentYear <= Math.min(...availableYearsData));
    nextYearBtn.disabled = show || (currentYear >= ACTUAL_CURRENT_YEAR);
    saveDayBtn.disabled = show;
    editDayBtn.disabled = show;
    // Можно блокировать клики по ячейкам календаря
    calendarGrid.style.pointerEvents = show ? 'none' : 'auto';
};

// --- Data Handling (API) ---
const loadDataForYear = async(year, userId, initData) => {
    if (!userId) {
        console.error("User ID не определен. Невозможно загрузить данные.");
        alert("Не удалось определить пользователя Telegram. Попробуйте перезапустить приложение.");
        showLoading(false);
        return false; // Возвращаем false при ошибке
    }

    if (!initData) {
        console.error("initData не определен. Невозможно загрузить данные.");
        alert("Не удалось определить initData. Попробуйте перезапустить приложение.");
        showLoading(false);
        return false;
    }

    showLoading(true);
    try {
        const response = await fetch(`${API_URL}?year=${year}&user_id=${userId}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Authorization': initData, // Передаем initData в headers
            }
        });

        if (!response.ok) {
            console.log('result', response);
            throw new Error(`Ошибка сети или сервера: ${response.statusText} (статус ${response.status})`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(`Ошибка API: ${result.message || 'Неизвестная ошибка API'}`);
        }

        // Обновляем данные календаря локально
        calendarData[year] = {}; // Очищаем данные для этого года перед заполнением
        for (const [dateStr, dayData] of Object.entries(result.data || {})) {
            const { year: entryYear, month, day } = parseDate(dateStr);
            if (!calendarData[entryYear]) calendarData[entryYear] = {};
            if (!calendarData[entryYear][month + 1]) calendarData[entryYear][month + 1] = {};
            calendarData[entryYear][month + 1][day] = dayData; // Store all data
        }
        // Обновляем список доступных лет
        availableYearsData = result.availableYears || [];

        return true; // Успех

    } catch (error) {
        console.error('Ошибка загрузки данных:', error);
        tg.showAlert(`Не удалось загрузить данные календаря: ${error.message}`);
        // В случае ошибки оставляем данные для года пустыми
        calendarData[year] = {};
        availableYearsData = []; // Сбрасываем доступные годы при ошибке
        return false; // Ошибка
    } finally {
        showLoading(false);
    }
};

const saveEntry = async(entryData, initData) => {
    if (!currentUserId) {
        console.error("User ID не определен. Невозможно сохранить данные.");
        alert("Не удалось определить пользователя Telegram. Попробуйте перезапустить приложение.");
        return false;
    }

    if (!initData) {
        console.error("initData не определен. Невозможно загрузить данные.");
        alert("Не удалось определить initData. Попробуйте перезапустить приложение.");
        showLoading(false);
        return false;
    }

    if (isLoading) return false; // Не сохранять во время другой загрузки

    showLoading(true);
    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Authorization': initData, // Передаем initData в headers
            },
            body: JSON.stringify({...entryData, user_id: currentUserId }) // Добавляем user_id
        });

        if (!response.ok) {
            throw new Error(`Ошибка сети или сервера: ${response.statusText}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(`Ошибка API: ${result.message || 'Неизвестная ошибка сохранения'}`);
        }

        console.log('Запись успешно сохранена:', result.message);
        return true; // Успех

    } catch (error) {
        console.error('Ошибка сохранения данных:', error);
        tg.showAlert(`Не удалось сохранить запись: ${error.message}`);
        return false; // Ошибка
    } finally {
        showLoading(false);
    }
};

// --- Settings (localStorage) ---
const loadSettings = () => {
    const savedTheme = localStorage.getItem('calendarTheme') || 'telegram';
    const savedLayout = localStorage.getItem('calendarLayout') || 'fill';
    const savedBackend = localStorage.getItem('calendarBackend') || 'stable';
    applyTheme(savedTheme);
    applyLayout(savedLayout);
    applyBackend(savedBackend);
    const themeRadio = document.querySelector(`input[name="theme"][value="${savedTheme}"]`);
    const layoutRadio = document.querySelector(`input[name="layout"][value="${savedLayout}"]`);
    const backendRadio = document.querySelector(`input[name="backend"][value="${savedBackend}"]`);
    if (themeRadio) themeRadio.checked = true;
    if (layoutRadio) layoutRadio.checked = true;
    if (backendRadio) backendRadio.checked = true;
};
const saveTheme = (theme) => localStorage.setItem('calendarTheme', theme);
const saveLayout = (layout) => localStorage.setItem('calendarLayout', layout);
const saveBackend = (backend) => localStorage.setItem('calendarBackend', backend);
const applyTheme = (themeName) => {

    if (themeName === 'telegram') {
        if (window.Telegram && Telegram.WebApp) {
            const theme = Telegram.WebApp.themeParams;
            document.documentElement.style.setProperty("--color-bg", theme.bg_color);
            document.documentElement.style.setProperty("--color-text", theme.text_color);
            document.documentElement.style.setProperty("--color-hint", theme.hint_color);
            document.documentElement.style.setProperty("--color-link", theme.link_color);
            document.documentElement.style.setProperty("--color-button-bg", theme.button_color);
            document.documentElement.style.setProperty("--color-button-text", theme.button_text_color);
            document.documentElement.style.setProperty("--color-secondary-bg", theme.secondary_bg_color);
            document.documentElement.style.setProperty("--color-header-bg", theme.secondary_bg_color);
            document.documentElement.style.setProperty("--color-bottom-bar-bg", theme.bottom_bar_bg_color);
            document.documentElement.style.setProperty("--color-accent-text", theme.accent_text_color);
            document.documentElement.style.setProperty("--color-section-bg", theme.section_bg_color);
            document.documentElement.style.setProperty("--color-section-header-text", theme.section_header_text_color);
            document.documentElement.style.setProperty("--color-section-separator", theme.section_separator_color);
            document.documentElement.style.setProperty("--color-subtitle-text", theme.subtitle_text_color);
            document.documentElement.style.setProperty("--color-settings-bg", theme.secondary_bg_color);
            document.documentElement.style.setProperty("--color-popup-bg", theme.bg_color);
            document.documentElement.style.setProperty("--color-day-bg", theme.secondary_bg_color);
            document.documentElement.style.setProperty("--color-border", theme.hint_color);
            document.documentElement.style.setProperty("--color-input-bg", theme.header_bg_color);
            document.documentElement.style.setProperty("--color-day-invalid-bg", theme.subtitle_text_color);


        }
    }
    appBody.dataset.theme = themeName;
    saveTheme(themeName);

};
const applyLayout = (layoutName) => {
    appBody.classList.remove('app--layout-full', 'app--layout-half', 'app--layout-compact', 'app--layout-fill');
    appBody.classList.add(`app--layout-${layoutName}`);
    saveLayout(layoutName);
    const daySize = layoutName === 'compact' ? '20px' : '25px';
    appContainer.style.setProperty('--day-size', daySize);
};

const applyBackend = (backendName) => {
    saveBackend(backendName);
}

// --- Rendering ---
const renderCalendar = (year) => {
        calendarGrid.innerHTML = '';
        monthsHeader.innerHTML = '';
        daysAxis.innerHTML = '';
        currentYearDisplay.textContent = year + " год в пикселях";

        // Шапка с месяцами
        MONTHS.forEach(month => {
            const monthEl = document.createElement('div');
            monthEl.classList.add('calendar__month');
            monthEl.textContent = month;
            monthEl.title = FULL_MONTHS[MONTHS.indexOf(month)];
            monthsHeader.appendChild(monthEl);
        });
        // Ось дней
        for (let day = 1; day <= 31; day++) {
            const dayNumEl = document.createElement('div');
            dayNumEl.classList.add('calendar__day-number');
            dayNumEl.textContent = day;
            daysAxis.appendChild(dayNumEl);
        }

        const yearData = calendarData[year] || {}; // Получаем данные для рендера
        const isCurrentActualYear = year === ACTUAL_CURRENT_YEAR;

        for (let day = 0; day < 31; day++) {
            for (let month = 0; month < 12; month++) {
                const dayCell = document.createElement('div');
                dayCell.classList.add('calendar__day');
                const dayOfMonth = day + 1;
                const daysInMonth = getDaysInMonth(year, month);

                if (dayOfMonth > daysInMonth) {
                    dayCell.classList.add('calendar__day--invalid');
                } else {
                    const dateStr = formatDate(year, month, dayOfMonth);
                    dayCell.dataset.date = dateStr;

                    // Используем данные из загруженного calendarData
                    const monthData = yearData[month + 1] || {};
                    const dayData = monthData[dayOfMonth];

                    if (dayData && dayData.color) { // Проверяем наличие ключа color
                        const moodInfo = MOOD_COLORS[dayData.color];
                        if (moodInfo) {
                            dayCell.style.backgroundColor = moodInfo.color;
                            //dayCell.title = `${moodInfo.label}${dayData.description ? ` - ${dayData.description.substring(0, 50)}...` : ''}`;
                            let titleText = `${moodInfo.label}${dayData.description ? ` - ${dayData.description.substring(0, 50)}...` : ''}`;
                            const activityEmojis = [];
                            if (Boolean(Number(dayData.alcohol))) activityEmojis.push(MOOD_ACTIVITY.alcohol.emoji);
                            if (Boolean(Number(dayData.sport))) activityEmojis.push(MOOD_ACTIVITY.sport.emoji);
                            if (Boolean(Number(dayData.sex))) activityEmojis.push(MOOD_ACTIVITY.sex.emoji);
                            if (Boolean(Number(dayData.friends))) activityEmojis.push(MOOD_ACTIVITY.friends.emoji);
                            if (Boolean(Number(dayData.romantic))) activityEmojis.push(MOOD_ACTIVITY.romantic.emoji);
                            if (Boolean(Number(dayData.crying))) activityEmojis.push(MOOD_ACTIVITY.crying.emoji);
                            if (Boolean(Number(dayData.WomanDay))) activityEmojis.push(MOOD_ACTIVITY.WomanDay.emoji);

                            if (activityEmojis.length > 0) {
                                titleText += ` ${activityEmojis.join('')}`;
                            }
                            dayCell.title = titleText;
                                dayCell.classList.add('calendar__day--filled');
                            } else {
                                console.warn(`Неизвестный mood_key '${dayData.color}' для даты ${dateStr}`);
                                // Можно добавить стиль для неизвестного цвета
                            }
                        }

                        // Логика кликабельности
                        if (dayData || isCurrentActualYear) { // Кликабельно если есть данные ИЛИ это текущий год
                            dayCell.addEventListener('click', handleDayClick);
                            dayCell.style.cursor = 'pointer';
                        } else {
                             dayCell.style.cursor = 'default'; // Пустые ячейки прошлых лет не кликабельны
                             dayCell.classList.add('calendar__day--past-empty');
                        }
                    }
                    calendarGrid.appendChild(dayCell);
                }
            }
             updateNavButtons(); // Обновляем состояние кнопок после рендера
        };

        const renderColorOptions = () => {
            popupColorsContainer.innerHTML = '';
            Object.entries(MOOD_COLORS).forEach(([key, { label, color }]) => {
                 const colorOption = document.createElement('div');
                 colorOption.classList.add('popup__color-option');
                 colorOption.dataset.colorKey = key;
                 colorOption.style.backgroundColor = color;
                 colorOption.title = label;
                 colorOption.addEventListener('click', handleColorSelect);
                 popupColorsContainer.appendChild(colorOption);
            });
        };

        const updateNavButtons = () => {
             // Используем availableYearsData из API для более точной проверки
             const minYear = availableYearsData.length > 0 ? Math.min(...availableYearsData) : currentYear;
             prevYearBtn.disabled = isLoading || currentYear <= minYear;
             nextYearBtn.disabled = isLoading || currentYear >= ACTUAL_CURRENT_YEAR;
        };

        const renderLegend = () => {
            legendContainer.innerHTML = ''; // Очищаем контейнер перед заполнением
            const legendList = document.createElement('ul'); // Создаем список для элементов легенды
            legendList.classList.add('colorsLegend__list');

            Object.entries(MOOD_COLORS).forEach(([key, { label, color }]) => {
                const legendItem = document.createElement('li');
                legendItem.classList.add('colorsLegend__item');

                const colorBox = document.createElement('span');
                colorBox.classList.add('colorsLegend__color');
                colorBox.style.backgroundColor = color;

                const colorLabel = document.createElement('span');
                colorLabel.classList.add('colorsLegend__label');
                colorLabel.textContent = label;

                legendItem.appendChild(colorBox);
                legendItem.appendChild(colorLabel);
                legendList.appendChild(legendItem);
            });

            legendContainer.appendChild(legendList);
        };

        function showToast(message, duration = 2500) {
            // Создаем элемент div для уведомления
            const toast = document.createElement('div');
            toast.textContent = message;
          
            // Добавляем стили для позиционирования и внешнего вида
            toast.style.position = 'fixed';
            toast.style.bottom = '20px';
            toast.style.left = '50%';
            toast.style.transform = 'translateX(-50%)';
            toast.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
            toast.style.color = 'white';
            toast.style.padding = '10px 20px';
            toast.style.borderRadius = '5px';
            toast.style.zIndex = '1000'; // Чтобы быть поверх других элементов
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease-in-out';
          
            // Добавляем элемент в DOM
            document.body.appendChild(toast);
          
            // Показываем уведомление с небольшой задержкой
            setTimeout(() => {
              toast.style.opacity = '1';
            }, 50);
          
            // Скрываем и удаляем уведомление через заданное время
            setTimeout(() => {
              toast.style.opacity = '0';
              setTimeout(() => {
                document.body.removeChild(toast);
              }, 300); // Задержка для завершения анимации скрытия
            }, duration);
          }

        // --- Event Handlers ---
        const handleDayClick = (event) => {
             if (isLoading) return; // Не обрабатывать клики во время загрузки
             const dayCell = event.target.closest('.calendar__day'); // Убедимся, что кликнули по ячейке
             if (!dayCell || dayCell.classList.contains('calendar__day--invalid') || dayCell.classList.contains('calendar__day--past-empty')) {
                 return;
             }

            selectedDate = dayCell.dataset.date;
            if (!selectedDate) return;

            const { year, month, day } = parseDate(selectedDate);
            const isCurrentActualYear = year === ACTUAL_CURRENT_YEAR;

            // Ищем данные в локальном кеше calendarData
            const dayData = calendarData[year]?.[month + 1]?.[day];

            tg.HapticFeedback.selectionChanged();

            if (dayData) {
                 showViewPopup(selectedDate, dayData);
            } else if (isCurrentActualYear) { // Редактировать можно только пустые дни текущего года
                 showEditPopup(selectedDate);
            }
        };

        const handleColorSelect = (event) => {
             const selectedOption = event.target;
             selectedColorKey = selectedOption.dataset.colorKey;
             document.querySelectorAll('.popup__color-option--selected').forEach(el => el.classList.remove('popup__color-option--selected'));
             selectedOption.classList.add('popup__color-option--selected');
             //popupColorsDescr.textContent = MOOD_COLORS[selectedColorKey].label;
             showToast(MOOD_COLORS[selectedColorKey].label);
             tg.HapticFeedback.selectionChanged();
        };

        const handleDescriptionInput = () => {
             const currentLength = dayDescriptionInput.value.length;
             charCounter.textContent = `${currentLength} / 300`;
        };

        const handleSaveDay = async () => {
            if (!selectedDate || !selectedColorKey) {
                tg.showAlert('Пожалуйста, выберите цвет настроения.');
                return;
            }
            const description = dayDescriptionInput.value.trim();
            // Get the checkbox values
            const alcohol = editAlcoholCheckbox.checked;
            const sport = editSportCheckbox.checked;
            const sex = editSexCheckbox.checked;
            const friends = editFriendsCheckbox.checked;
            const romantic = editRomanticCheckbox.checked;
            const crying = editCryingCheckbox.checked;
            const WomanDay = editWomanDayCheckbox.checked;
            
            const entryData = {
                date: selectedDate,
                mood_key: selectedColorKey,
                description: description,
                alcohol: alcohol,
                sport: sport,
                sex: sex,
                friends: friends,
                romantic: romantic,
                crying: crying,
                WomanDay: WomanDay,
            };

            const success = await saveEntry(entryData, initData); // шлём initData при вызове

            if (success) {
                hideEditPopup();
                tg.HapticFeedback.notificationOccurred('success');
                // Обновляем данные для текущего года после сохранения
                await loadDataForYear(currentYear, currentUserId, initData); // шлём initData при вызове
                renderCalendar(currentYear); // Перерисовываем календарь с новыми данными
            } else {
                tg.HapticFeedback.notificationOccurred('warning');
                // Ошибка уже показана в saveEntry
            }
        };

        const handleEditFromView = () => {
             if (!selectedDate) return;
             const { year, month, day } = parseDate(selectedDate);
             const dayData = calendarData[year]?.[month + 1]?.[day];
             if (year === ACTUAL_CURRENT_YEAR) { // Доп. проверка, что редактируем текущий год
                hideViewPopup();
                showEditPopup(selectedDate, dayData); // Передаем данные для предзаполнения
                tg.HapticFeedback.impactOccurred("soft");
             } else {
                tg.showAlert("Редактировать можно только записи текущего года.");
             }
        };

        const handlePrevYear = async () => {
            if (isLoading) return;
            const minYear = availableYearsData.length > 0 ? Math.min(...availableYearsData) : currentYear -1; // Определяем минимальный год
            if (currentYear > minYear) {
                currentYear--;
                const success = await loadDataForYear(currentYear, currentUserId, initData); // шлём initData при вызове
                if (success) {
                    renderCalendar(currentYear);
                    tg.HapticFeedback.impactOccurred("soft");
                } else {
                    // Ошибка загрузки обработана в loadDataForYear, откатываем год назад
                    currentYear++;
                }
            }
        };

        const handleNextYear = async () => {
             if (isLoading) return;
             if (currentYear < ACTUAL_CURRENT_YEAR) {
                 currentYear++;
                 const success = await loadDataForYear(currentYear, currentUserId, initData); // шлём initData при вызове
                 if (success) {
                    renderCalendar(currentYear);
                    tg.HapticFeedback.selectionChanged();
                 } else {
                    // Ошибка загрузки обработана в loadDataForYear, откатываем год назад
                     currentYear--;
                 }
             }
        };

        // --- Handlers for Settings, Popups ---
         const handleSettingsToggle = () => settingsPanel.classList.toggle('settings--visible');
         const handleThemeChange = (event) => { if (event.target.type === 'radio') applyTheme(event.target.value); tg.HapticFeedback.impactOccurred("medium"); };
         const handleLayoutChange = (event) => { if (event.target.type === 'radio') applyLayout(event.target.value); tg.HapticFeedback.impactOccurred("medium"); };
         const handleBackendChange = (event) => { if (event.target.type === 'radio') applyBackend(event.target.value); tg.HapticFeedback.impactOccurred("medium"); location.reload(); };

        const showEditPopup = (dateStr, existingData = null) => {
            selectedDate = dateStr;
            const { year, month, day } = parseDate(dateStr);
            editPopupHeader.textContent = `Запись за ${day} ${FULL_MONTHS[month]} ${year}`; // Используем полное название месяца
            dayDescriptionInput.value = '';
            selectedColorKey = null;
            
            editAlcoholCheckbox.checked = false;
            editSportCheckbox.checked = false;
            editSexCheckbox.checked = false;
            editFriendsCheckbox.checked = false;
            editRomanticCheckbox.checked = false;
            editCryingCheckbox.checked = false;
            editWomanDayCheckbox.checked = false;

            document.querySelectorAll('.popup__color-option--selected').forEach(el => el.classList.remove('popup__color-option--selected'));
            if (existingData) {
                selectedColorKey = existingData.color;
                dayDescriptionInput.value = existingData.description || '';
                const colorOption = popupColorsContainer.querySelector(`[data-color-key="${selectedColorKey}"]`);
                if (colorOption) colorOption.classList.add('popup__color-option--selected');
   
                // Set checkboxes based on existing data
                editAlcoholCheckbox.checked = Boolean(Number(existingData.alcohol));
                editSportCheckbox.checked = Boolean(Number(existingData.sport));
                editSexCheckbox.checked = Boolean(Number(existingData.sex));
                editFriendsCheckbox.checked = Boolean(Number(existingData.friends));
                editRomanticCheckbox.checked = Boolean(Number(existingData.romantic));
                editCryingCheckbox.checked = Boolean(Number(existingData.crying));
                editWomanDayCheckbox.checked = Boolean(Number(existingData.WomanDay));
           }
            handleDescriptionInput();
            editPopup.classList.add('popup--visible');
            // dayDescriptionInput.focus();
        };
        const hideEditPopup = () => {
            editPopup.classList.remove('popup--visible');
            selectedDate = null; selectedColorKey = null;
        };
        const showViewPopup = (dateStr, dayData) => {
            selectedDate = dateStr;
            const { year, month, day } = parseDate(dateStr);
            const moodInfo = MOOD_COLORS[dayData.color];
            viewPopupHeader.textContent = `Просмотр: ${day} ${FULL_MONTHS[month]} ${year}`; // Используем полное название месяца
            if (moodInfo) {
                viewPopupColor.style.backgroundColor = moodInfo.color;
                viewPopupMood.textContent = moodInfo.label;
            } else {
                viewPopupColor.style.backgroundColor = 'transparent';
                viewPopupMood.textContent = 'N/A';
            }
            viewPopupDescription.textContent = dayData.description || '(Нет описания)';
            
            // Set checkbox values in View Popup
            viewAlcoholCheckbox.checked = Boolean(Number(dayData.alcohol));
            viewSportCheckbox.checked = Boolean(Number(dayData.sport));
            viewSexCheckbox.checked = Boolean(Number(dayData.sex));
            viewFriendsCheckbox.checked = Boolean(Number(dayData.friends));
            viewRomanticCheckbox.checked = Boolean(Number(dayData.romantic));
            viewCryingCheckbox.checked = Boolean(Number(dayData.crying));
            viewWomanDayCheckbox.checked = Boolean(Number(dayData.WomanDay));

            editDayBtn.style.display = (year === ACTUAL_CURRENT_YEAR) ? 'inline-block' : 'none';
            viewPopup.classList.add('popup--visible');
        };
        const hideViewPopup = () => viewPopup.classList.remove('popup--visible');

        const handleShare = async () => {
            if (shareButton.disabled) {
                console.log("html2canvas ещё не загружен");
                tg.showAlert('Пожалуйста, подождите пару секунд или обновите страницу.');
                return;
            }
            if (isLoading) {
                tg.showAlert('Пожалуйста, дождитесь завершения текущей операции.');
                return;
            }

            showLoading(true);
            tg.MainButton.showProgress();

            try {
                // --- Отладка: Проверка наличия элементов ---
                console.log("Начало создания скриншота...");
                const appElement = document.querySelector('.app');
                const legendElement = document.getElementById('legend');
                const bodyElement = document.body;

                console.log("Элемент .app:", appElement);
                console.log("Элемент #legend:", legendElement);
                console.log("Элемент body:", bodyElement);

                if (!appElement || !legendElement) {
                    throw new Error("Не удалось найти элементы '.app' или '#legend' для скриншота.");
                }

                // --- Отладка: Проверка html2canvas ---
                if (typeof html2canvas === 'undefined') {
                    throw new Error('Библиотека html2canvas не найдена или не загрузилась корректно.');
                }
                console.log("html2canvas доступен:", typeof html2canvas);

                // --- Отладка: Выбор элементов ---
                // Вариант 1: Только календарь и легенда (попробуем сначала его)
                //const elementsToCapture = [appElement, legendElement];
                // Вариант 2: Весь body (раскомментировать, если вариант 1 не работает)
                const elementsToCapture = [bodyElement];
                
                console.log("Элементы для захвата:", elementsToCapture);

                const imageBlob = await captureElementsAsImage(elementsToCapture, MIN_SHARE_WIDTH);
                console.log("Скриншот создан, Blob:", imageBlob);
                if (imageBlob) {
                    await sendImageToBackend(imageBlob, currentUserId, initData);
                } else {
                    throw new Error("Созданный Blob изображения пуст.");
                }
            } catch (error) {
                console.error('Ошибка в handleShare:', error); // Более конкретная ошибка
                // Проверим, есть ли у объекта ошибки свойство message
                const errorMessage = error && error.message ? error.message : String(error);
                tg.showAlert('Не удалось создать скриншот: ' + errorMessage);
            } finally {
                if (isLoading) { // Дополнительная проверка на случай, если showLoading(false) уже был вызван
                    showLoading(false); 
                }
                tg.MainButton.hideProgress();
            }
        };

        const checkInterval = setInterval(() => {
            if (typeof html2canvas !== 'undefined') {
                shareButton.disabled = false;
                shareButton.style.opacity = 1;
                shareButton.style.cursor = 'pointer';
                clearInterval(checkInterval);
            }
        }, 500);

        // --- Initialization ---
        const init = async () => {
             tg.ready(); // Сообщаем Telegram, что WebApp готово

             const userData = tg.initDataUnsafe?.user;
             initData = tg.initData;

             tg.SettingsButton.show();
             tg.onEvent('settingsButtonClicked', handleSettingsToggle);

             if (!userData) {
                 console.error("Не удалось получить данные пользователя из Telegram.");
                 // Можно показать сообщение об ошибке пользователю на странице
                 // document.body.innerHTML = '<h1>Ошибка: Не удалось получить данные пользователя Telegram. Запустите приложение через бота.</h1>';
                 alert("Ошибка: Не удалось получить данные пользователя Telegram. Убедитесь, что вы запускаете это приложение через Telegram.");
                 return; // Прерываем инициализацию
             }

             currentUserId = userData.id;
             console.log("Telegram User ID:", currentUserId, "Имя:", userData.first_name);

             loadSettings(); // Загружаем настройки UI
             renderColorOptions(); // Рендерим кнопки выбора цвета
             renderLegend();

             if(localStorage.getItem('calendarBackend') == "test"){
                API_URL = "/pixels/api/test.php";
             }

             // Загружаем данные для текущего года
             const initialLoadSuccess = await loadDataForYear(currentYear, currentUserId, initData); // отправляем initData для проверки запроса
             if (initialLoadSuccess) {
                renderCalendar(currentYear); // Рендерим календарь
             } else {
                 // Показываем пустой календарь или сообщение об ошибке
                 renderCalendar(currentYear); // Рендерим пустой, updateNavButtons отработает
             }

             // --- Event Listeners (без изменений в добавлении) ---
             // Клики по ячейкам делегируются в renderCalendar
             dayDescriptionInput.addEventListener('input', handleDescriptionInput);
             saveDayBtn.addEventListener('click', handleSaveDay);
             closeEditPopupBtn.addEventListener('click', hideEditPopup);
             cancelEditBtn.addEventListener('click', hideEditPopup);
             closeViewPopupBtn.addEventListener('click', hideViewPopup);
             cancelViewBtn.addEventListener('click', hideViewPopup);
             editDayBtn.addEventListener('click', handleEditFromView);
             prevYearBtn.addEventListener('click', handlePrevYear);
             nextYearBtn.addEventListener('click', handleNextYear);
             shareButton.addEventListener('click', handleShare);
             settingsButton.addEventListener('click', handleSettingsToggle);
             closeSettingsBtn.addEventListener('click', handleSettingsToggle);
             themeOptions.addEventListener('change', handleThemeChange);
             layoutOptions.addEventListener('change', handleLayoutChange);
             backendOptions.addEventListener('change', handleBackendChange);
             document.addEventListener('keydown', (e) => {
                if (isLoading) return; // Не обрабатывать Esc во время загрузки
                if (e.key === 'Escape') {
                    if (editPopup.classList.contains('popup--visible')) hideEditPopup();
                    else if (viewPopup.classList.contains('popup--visible')) hideViewPopup();
                    else if (settingsPanel.classList.contains('settings--visible')) handleSettingsToggle();
                }
             });
             [editPopup, viewPopup].forEach(popup => {
                popup.addEventListener('click', (e) => {
                    if (e.target === popup) {
                        if (popup === editPopup) hideEditPopup();
                        else if (popup === viewPopup) hideViewPopup();
                    }
                });
             });
        };

        // --- Start the application ---
        init(); // Запускаем асинхронную инициализацию