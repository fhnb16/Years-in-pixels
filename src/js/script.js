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
const settingsButton = document.getElementById('settingsButton');
const settingsPanel = document.getElementById('settingsPanel');
const closeSettingsBtn = document.getElementById('closeSettingsBtn');
const themeOptions = document.getElementById('themeOptions');
const layoutOptions = document.getElementById('layoutOptions');

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

// --- Telegram WebApp ---
const tg = window.Telegram.WebApp;

// --- Utility Functions (остаются те же) ---
const getDaysInMonth = (year, month) => new Date(year, month + 1, 0).getDate();
const formatDate = (year, month, day) => `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
const parseDate = (dateString) => {
    const [year, month, day] = dateString.split('-').map(Number);
    return { year, month: month - 1, day }; // month is 0-indexed internally
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
const loadDataForYear = async(year, userId) => {
    if (!userId) {
        console.error("User ID не определен. Невозможно загрузить данные.");
        alert("Не удалось определить пользователя Telegram. Попробуйте перезапустить приложение.");
        showLoading(false);
        return false; // Возвращаем false при ошибке
    }
    showLoading(true);
    try {
        const response = await fetch(`${API_URL}?year=${year}&user_id=${userId}`, {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        });

        if (!response.ok) {
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

const saveEntry = async(entryData) => {
    if (!currentUserId) {
        console.error("User ID не определен. Невозможно сохранить данные.");
        alert("Не удалось определить пользователя Telegram. Попробуйте перезапустить приложение.");
        return false;
    }
    if (isLoading) return false; // Не сохранять во время другой загрузки

    showLoading(true);
    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
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
    const savedTheme = localStorage.getItem('calendarTheme') || 'light';
    const savedLayout = localStorage.getItem('calendarLayout') || 'fill';
    applyTheme(savedTheme);
    applyLayout(savedLayout);
    const themeRadio = document.querySelector(`input[name="theme"][value="${savedTheme}"]`);
    const layoutRadio = document.querySelector(`input[name="layout"][value="${savedLayout}"]`);
    if (themeRadio) themeRadio.checked = true;
    if (layoutRadio) layoutRadio.checked = true;
};
const saveTheme = (theme) => localStorage.setItem('calendarTheme', theme);
const saveLayout = (layout) => localStorage.setItem('calendarLayout', layout);
const applyTheme = (themeName) => {
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
                            dayCell.title = `${moodInfo.label}${dayData.description ? ` - ${dayData.description.substring(0, 50)}...` : ''}`;
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

        function showToast(message, duration = 3000) {
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

            const success = await saveEntry(entryData);

            if (success) {
                hideEditPopup();
                // Обновляем данные для текущего года после сохранения
                await loadDataForYear(currentYear, currentUserId);
                renderCalendar(currentYear); // Перерисовываем календарь с новыми данными
            } else {
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
             } else {
                tg.showAlert("Редактировать можно только записи текущего года.");
             }
        };

        const handlePrevYear = async () => {
            if (isLoading) return;
            const minYear = availableYearsData.length > 0 ? Math.min(...availableYearsData) : currentYear -1; // Определяем минимальный год
            if (currentYear > minYear) {
                currentYear--;
                const success = await loadDataForYear(currentYear, currentUserId);
                if (success) {
                    renderCalendar(currentYear);
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
                 const success = await loadDataForYear(currentYear, currentUserId);
                 if (success) {
                    renderCalendar(currentYear);
                 } else {
                    // Ошибка загрузки обработана в loadDataForYear, откатываем год назад
                     currentYear--;
                 }
             }
        };

        // --- Handlers for Settings, Popups ---
         const handleSettingsToggle = () => settingsPanel.classList.toggle('settings--visible');
         const handleThemeChange = (event) => { if (event.target.type === 'radio') applyTheme(event.target.value); };
         const handleLayoutChange = (event) => { if (event.target.type === 'radio') applyLayout(event.target.value); };

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


        // --- Initialization ---
        const init = async () => {
             tg.ready(); // Сообщаем Telegram, что WebApp готово

             const userData = tg.initDataUnsafe?.user;

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

             // Загружаем данные для текущего года
             const initialLoadSuccess = await loadDataForYear(currentYear, currentUserId);
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
             settingsButton.addEventListener('click', handleSettingsToggle);
             closeSettingsBtn.addEventListener('click', handleSettingsToggle);
             themeOptions.addEventListener('change', handleThemeChange);
             layoutOptions.addEventListener('change', handleLayoutChange);
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