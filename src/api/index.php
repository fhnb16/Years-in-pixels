<?php

require_once __DIR__ . '/lib.php';

// --- CORS: отвечаем только тем источникам, что перечислены в config.php ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowed_origins ?? [], true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- Подключение к Базе Данных (PDO) ---
try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    // Наружу — без подробностей, они уже в журнале: текст ошибки PDO выдаёт имя базы и пользователя.
    echo json_encode(['success' => false, 'message' => 'База данных временно недоступна']);
    exit;
}

// --- Функции API ---

// Проверка подписи Telegram (checkHash / convertInitData / parseInitData) живёт в lib.php.

/**
 * Обрабатывает и сохраняет изображение пользователя
 */
function saveUserImage($pdo, $botToken, $input) {
    // Валидация входных данных
    if (!$input || !isset($input['user_id'], $input['image'], $_SERVER['HTTP_AUTHORIZATION'])) {
        throw new Exception('Неполные данные. Требуются user_id и image.');
    }

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $base64Image = $input['image'];
    $initData = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($userId === false || $userId <= 0) {
        throw new Exception('Некорректный user_id');
    }

    // Проверка hash
    if (!checkHash($initData, $botToken)) {
        throw new Exception('Неверный hash initData');
    }

    try {
        // Декодируем base64 изображение
        $imageData = base64_decode($base64Image);
        if ($imageData === false) {
            throw new Exception('Неверные данные изображения');
        }

        // Проверяем, является ли это действительно изображением
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);
        
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception('Неподдерживаемый формат изображения');
        }

        // Определяем расширение файла
        $extension = '';
        switch ($mimeType) {
            case 'image/jpeg':
                $extension = 'jpg';
                break;
            case 'image/png':
                $extension = 'png';
                break;
            case 'image/gif':
                $extension = 'gif';
                break;
        }

        // Создаем директорию если не существует
        $uploadDir = __DIR__ . '/user_screens';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Генерируем уникальное имя файла
        $filename = 'user_' . $userId . '_' . time() . '_' . uniqid() . '.' . $extension;
        $filepath = $uploadDir . '/' . $filename;

        // Сохраняем файл
        if (file_put_contents($filepath, $imageData) === false) {
            throw new Exception('Ошибка сохранения файла');
        }

        // Публичный адрес: через инбаунд-реле, если оно задано в настройках, иначе адрес самого сервера.
        // По этой ссылке картинку скачивает сам Telegram, поэтому она должна быть доступна снаружи.
        $publicUrl = public_url('user_screens/' . $filename);

        // Обновляем запись в базе данных
        $stmt = $pdo->prepare("UPDATE calendar_users SET user_shareimage = :share_image WHERE user_id = :user_id");
        $result = $stmt->execute([
            'user_id' => $userId,
            'share_image' => $publicUrl
        ]);

        if (!$result) {
            throw new Exception('Ошибка обновления базы данных');
        }

        return [
            'success' => true,
            'message' => 'Изображение успешно сохранено',
            'url' => $publicUrl,
            'path' => $filepath,
            'filename' => $filename
        ];

    } catch (Exception $e) {
        // Удаляем файл если он был создан, но произошла ошибка
        if (isset($filepath) && file_exists($filepath)) {
            unlink($filepath);
        }
        throw $e;
    }
}


/**
 * Получает геолокацию пользователя по IP и обновляет мета-информацию
 */
function updateUserGeolocation($pdo, $userId) {
    try {
        // Получаем IP пользователя
        $ip = getUserIP();
        
        if (!$ip || $ip === '127.0.0.1' || $ip === '::1') {
            return; // Пропускаем локальные IP
        }
        
        // Проверяем текущий IP пользователя в базе
        $checkStmt = $pdo->prepare("SELECT user_metainfo, user_timezone, user_latestip FROM calendar_users WHERE user_id = :user_id");
        $checkStmt->execute(['user_id' => $userId]);
        $userData = $checkStmt->fetch();
        
        // Проверяем, изменился ли IP
        $ipChanged = !$userData || $userData['user_latestip'] !== $ip;
        
        // Если IP не изменился и уже есть данные о часовом поясе и метаинформации, пропускаем
        if (!$ipChanged && $userData && !empty($userData['user_timezone']) && !empty($userData['user_metainfo'])) {
            // Но все равно обновляем IP (на случай если только IP новый)
            $updateIpStmt = $pdo->prepare("UPDATE calendar_users SET user_latestip = :ip WHERE user_id = :user_id AND (user_latestip IS NULL OR user_latestip != :ip)");
            $updateIpStmt->execute(['user_id' => $userId, 'ip' => $ip]);
            return;
        }
        
        // Если IP изменился или нет данных о геолокации, запрашиваем новую информацию
        if ($ipChanged) {
            // Запрос к GeoIP сервису (используем ipapi.co - бесплатный сервис)
            $geoUrl = "https://ipapi.co/{$ip}/json/";
            
            // Используем cURL для запроса
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $geoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'CalendarApp/1.0');
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($response && $httpCode === 200) {
                $geoData = json_decode($response, true);
                
                if ($geoData && !isset($geoData['error'])) {
                    // Подготавливаем мета-информацию
                    $metaInfo = [
                        'country' => $geoData['country_name'] ?? null,
                        'region' => $geoData['region'] ?? null,
                        'city' => $geoData['city'] ?? null,
                        'country_code' => $geoData['country_code'] ?? null,
                        'latitude' => $geoData['latitude'] ?? null,
                        'longitude' => $geoData['longitude'] ?? null,
                        'isp' => $geoData['org'] ?? null,
                    ];
                    
                    $timezone = $geoData['timezone'] ?? null;
                    
                    // Обновляем данные пользователя с новой геоинформацией
                    $updateSql = "UPDATE calendar_users SET 
                                 user_metainfo = :meta_info, 
                                 user_timezone = COALESCE(user_timezone, :timezone),
                                 user_latestip = :ip
                                 WHERE user_id = :user_id";
                    
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([
                        'user_id' => $userId,
                        'meta_info' => json_encode($metaInfo, JSON_UNESCAPED_UNICODE),
                        'timezone' => $timezone,
                        'ip' => $ip
                    ]);
                } else {
                    // Если геоинформация недоступна, обновляем только IP
                    $updateIpStmt = $pdo->prepare("UPDATE calendar_users SET user_latestip = :ip WHERE user_id = :user_id");
                    $updateIpStmt->execute(['user_id' => $userId, 'ip' => $ip]);
                }
            } else {
                // Если запрос не удался, обновляем только IP
                $updateIpStmt = $pdo->prepare("UPDATE calendar_users SET user_latestip = :ip WHERE user_id = :user_id");
                $updateIpStmt->execute(['user_id' => $userId, 'ip' => $ip]);
            }
        } else {
            // Если IP не изменился, но нет данных о геолокации, пробуем получить их
            // Запрос к GeoIP сервису
            $geoUrl = "https://ipapi.co/{$ip}/json/";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $geoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'CalendarApp/1.0');
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($response && $httpCode === 200) {
                $geoData = json_decode($response, true);
                
                if ($geoData && !isset($geoData['error'])) {
                    $metaInfo = [
                        'country' => $geoData['country_name'] ?? null,
                        'region' => $geoData['region'] ?? null,
                        'city' => $geoData['city'] ?? null,
                        'country_code' => $geoData['country_code'] ?? null,
                        'latitude' => $geoData['latitude'] ?? null,
                        'longitude' => $geoData['longitude'] ?? null,
                        'isp' => $geoData['org'] ?? null,
                    ];
                    
                    $timezone = $geoData['timezone'] ?? null;
                    
                    // Обновляем только недостающие данные
                    $updateSql = "UPDATE calendar_users SET 
                                 user_metainfo = COALESCE(user_metainfo, :meta_info), 
                                 user_timezone = COALESCE(user_timezone, :timezone),
                                 user_latestip = :ip
                                 WHERE user_id = :user_id";
                    
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([
                        'user_id' => $userId,
                        'meta_info' => json_encode($metaInfo, JSON_UNESCAPED_UNICODE),
                        'timezone' => $timezone,
                        'ip' => $ip
                    ]);
                }
            }
        }
        
    } catch (Exception $e) {
        Log::warn('api', (string)("Geolocation error for user {$userId}: " . $e->getMessage()));
        // Не прерываем основной процесс из-за ошибки геолокации
        // Пытаемся обновить хотя бы IP
        try {
            $ip = getUserIP();
            if ($ip) {
                $updateIpStmt = $pdo->prepare("UPDATE calendar_users SET user_latestip = :ip WHERE user_id = :user_id");
                $updateIpStmt->execute(['user_id' => $userId, 'ip' => $ip]);
            }
        } catch (Exception $e2) {
            Log::warn('api', (string)("IP update error for user {$userId}: " . $e2->getMessage()));
        }
    }
}

/**
 * Получает реальный IP пользователя
 */
function getUserIP() {
    // Проверяем различные заголовки, которые могут содержать реальный IP
    $ipKeys = [
        'HTTP_CF_CONNECTING_IP',   // Cloudflare
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            // Если IP в формате "IP, IP2, IP3", берем первый
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return null;
}

/**
 * Обновляет или создает запись пользователя в таблице calendar_users
 */
function upsertUser($pdo, $initData, $botToken) {
    // Проверка hash
    if (!checkHash($initData, $botToken)) {
        throw new Exception('Неверный hash initData');
    }
    
    // Парсим initData
    $parsedData = parseInitData($initData);
    
    if (!isset($parsedData['user'])) {
        throw new Exception('В initData отсутствуют данные пользователя');
    }
    
    $userData = json_decode($parsedData['user'], true);
    if (!$userData) {
        throw new Exception('Невозможно распарсить данные пользователя');
    }
    
    $userId = $userData['id'] ?? null;
    $firstName = $userData['first_name'] ?? '';
    $lastName = $userData['last_name'] ?? '';
    $username = $userData['username'] ?? '';
    
    if (!$userId) {
        throw new Exception('Не удалось получить user_id из initData');
    }
    
    try {
        // Используем INSERT ... ON DUPLICATE KEY UPDATE для "upsert"
        $sql = "INSERT INTO calendar_users (user_id, user_firstname, user_lastname, user_nickname, user_lastentry) 
                VALUES (:user_id, :first_name, :last_name, :username, :last_entry)
                ON DUPLICATE KEY UPDATE
                    user_firstname = VALUES(user_firstname),
                    user_lastname = VALUES(user_lastname),
                    user_nickname = VALUES(user_nickname),
                    user_lastentry = COALESCE(VALUES(user_lastentry), user_lastentry)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'last_entry' => date('Y-m-d') // Обновляем дату последней активности
        ]);

        // Добавляем вызов функции геолокации
        updateUserGeolocation($pdo, $userId);
        
        return $userId;
        
    } catch (PDOException $e) {
        throw new Exception('Ошибка сохранения данных пользователя: ' . $e->getMessage());
    }
}

/**
 * Получение данных календаря для пользователя за указанный год.
 */
function getCalendarData($pdo, $botToken) {
    if (!isset($_GET['year']) || !isset($_GET['user_id']) || !isset($_SERVER['HTTP_AUTHORIZATION'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Параметры year, user_id и init_data обязательны']);
        exit;
    }

    $year = filter_var($_GET['year'], FILTER_VALIDATE_INT);
    $userId = filter_var($_GET['user_id'], FILTER_VALIDATE_INT);
    $initData = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($year === false || $userId === false || $userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректные параметры year или user_id']);
        exit;
    }

    // Проверка hash и обновление данных пользователя
    try {
        upsertUser($pdo, $initData, $botToken);
    } catch (Exception $e) {
        http_response_code(403);
        Log::warn('auth', (string)("ERROR: User upsert failed. " . $e->getMessage()));
        echo json_encode(['success' => false, 'message' => 'Ошибка обработки данных пользователя: ' . $e->getMessage()]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT entry_date, mood_key, description, alcohol, sport, sex, friends, romantic, crying, WomanDay FROM calendar_entries WHERE user_id = :user_id AND YEAR(entry_date) = :year ORDER BY entry_date ASC");
        $stmt->execute(['user_id' => $userId, 'year' => $year]);
        $results = $stmt->fetchAll();

        // Форматируем результат для JavaScript (плоский объект date => data)
        $formattedData = [];
        foreach ($results as $row) {
            $formattedData[$row['entry_date']] = [
                'color' => $row['mood_key'],
                'description' => $row['description'] ?? '',
                'alcohol' => $row['alcohol'],
                'sport' => $row['sport'],
                'sex' => $row['sex'],
                'friends' => $row['friends'],
                'romantic' => $row['romantic'],
                'crying' => $row['crying'],
                'WomanDay' => $row['WomanDay'],
            ];
        }

        // Дополнительно получим список лет, за которые есть данные у пользователя
        $stmtYears = $pdo->prepare("SELECT DISTINCT YEAR(entry_date) as year FROM calendar_entries WHERE user_id = :user_id ORDER BY year DESC");
        $stmtYears->execute(['user_id' => $userId]);
        $availableYears = $stmtYears->fetchAll(PDO::FETCH_COLUMN, 0);

        echo json_encode([
            'success' => true,
            'data' => $formattedData, // Отправляем данные за запрошенный год
            'availableYears' => $availableYears // Отправляем список доступных лет
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ошибка выполнения запроса к БД: ' . $e->getMessage()]);
        exit;
    }
}

/**
 * Создание или обновление записи в календаре.
 */
function saveCalendarEntry($pdo, $botToken, $input) {

    // Валидация входных данных
    if (!$input || !isset($input['user_id'], $input['date'], $input['mood_key'], $_SERVER['HTTP_AUTHORIZATION'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неполные данные. Требуются user_id, date, mood_key, init_data.']);
        exit;
    }

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT); // Или FILTER_VALIDATE_FLOAT
    $date = $input['date']; // Дополнительно можно валидировать формат YYYY-MM-DD
    $moodKey = trim($input['mood_key']);
    $description = isset($input['description']) ? trim($input['description']) : null; // Описание опционально
    $alcohol = isset($input['alcohol']) ? (bool)$input['alcohol'] : false;
    $sport = isset($input['sport']) ? (bool)$input['sport'] : false;
    $sex = isset($input['sex']) ? (bool)$input['sex'] : false;
    $friends = isset($input['friends']) ? (bool)$input['friends'] : false;
    $romantic = isset($input['romantic']) ? (bool)$input['romantic'] : false;
    $crying = isset($input['crying']) ? (bool)$input['crying'] : false;
    $WomanDay = isset($input['WomanDay']) ? (bool)$input['WomanDay'] : false;
    $initData = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Простая валидация (добавьте более строгую при необходимости)
    if ($userId === false || $userId <= 0 || empty($moodKey) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
         http_response_code(400);
         echo json_encode(['success' => false, 'message' => 'Некорректные данные. Проверьте user_id, date (YYYY-MM-DD), mood_key.']);
         exit;
    }
    
    // Проверка hash и обновление данных пользователя
    try {
        upsertUser($pdo, $initData, $botToken);
    } catch (Exception $e) {
        http_response_code(403);
        Log::warn('auth', (string)("ERROR: User upsert failed. " . $e->getMessage()));
        echo json_encode(['success' => false, 'message' => 'Ошибка обработки данных пользователя: ' . $e->getMessage()]);
        exit;
    }

    try {
        // Используем INSERT ... ON DUPLICATE KEY UPDATE для "upsert"
        $sql = "INSERT INTO calendar_entries (user_id, entry_date, mood_key, description, alcohol, sport, sex, friends, romantic, crying, WomanDay)
                VALUES (:user_id, :entry_date, :mood_key, :description, :alcohol, :sport, :sex, :friends, :romantic, :crying, :WomanDay)
                ON DUPLICATE KEY UPDATE
                    mood_key = VALUES(mood_key),
                    description = VALUES(description),
                    alcohol = VALUES(alcohol),
                    sport = VALUES(sport),
                    sex = VALUES(sex),
                    friends = VALUES(friends),
                    romantic = VALUES(romantic),
                    crying = VALUES(crying),
                    WomanDay = VALUES(WomanDay)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'entry_date' => $date,
            'mood_key' => $moodKey,
            'description' => $description,
            'alcohol' => $alcohol,
            'sport' => $sport,
            'sex' => $sex,
            'friends' => $friends,
            'romantic' => $romantic,
            'crying' => $crying,
            'WomanDay' => $WomanDay,
        ]);
        
        // Обновляем дату последней записи пользователя
        $updateUserStmt = $pdo->prepare("UPDATE calendar_users SET user_lastentry = :last_entry, user_entriestotal = user_entriestotal + 1 WHERE user_id = :user_id");
        $updateUserStmt->execute([
            'user_id' => $userId,
            'last_entry' => $date
        ]);

        if ($stmt->rowCount() > 0) {
             echo json_encode(['success' => true, 'message' => 'Запись успешно сохранена/обновлена']);
        } else {
             // Если rowCount == 0, возможно, данные не изменились (UPDATE не сработал)
             // Или был INSERT. В PDO сложно различить. Просто считаем успехом, если нет ошибки.
             echo json_encode(['success' => true, 'message' => 'Запись обработана (возможно, без изменений)']);
        }

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ошибка сохранения в БД: ' . $e->getMessage()]);
        exit;
    }
}

function hideCalendarEntry($pdo, $botToken, $input) {

    // Валидация входных данных
    if (!$input || !isset($input['user_id'], $input['date'], $_SERVER['HTTP_AUTHORIZATION'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неполные данные. Требуются user_id, date, init_data.']);
        exit;
    }

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $date = $input['date']; // Ожидается формат YYYY-MM-DD
    $initData = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Простая валидация
    if ($userId === false || $userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
         http_response_code(400);
         echo json_encode(['success' => false, 'message' => 'Некорректные данные. Проверьте user_id и date (YYYY-MM-DD).']);
         exit;
    }
    
    // Проверка hash и обновление данных пользователя (опционально, для логирования)
    try {
        upsertUser($pdo, $initData, $botToken);
    } catch (Exception $e) {
        // Можно не прерывать выполнение, если upsert не критичен для удаления, но логировать стоит
        Log::warn('auth', (string)("INFO (hideEntry): User upsert failed (not critical). " . $e->getMessage()));
        // Если строго, можно прервать:
        // http_response_code(403);
        // echo json_encode(['success' => false, 'message' => 'Ошибка обработки данных пользователя: ' . $e->getMessage()]);
        // exit;
    }

    try {
        // --- ВАРИАНТ 1: Пометить запись как удалённую ---
        // Предполагается, что в таблице `calendar_entries` есть столбец `is_deleted` (TINYINT(1) DEFAULT 0)
        //$sql = "UPDATE calendar_entries SET is_deleted = 1 WHERE user_id = :user_id AND entry_date = :entry_date";

        // --- ВАРИАНТ 2: Удалить запись физически ---
         $sql = "DELETE FROM calendar_entries WHERE user_id = :user_id AND entry_date = :entry_date";

        // --- ВАРИАНТ 3: Изменить mood_key на специальный ---
        // $sql = "UPDATE calendar_entries SET mood_key = 'deleted' WHERE user_id = :user_id AND entry_date = :entry_date";
        // Убедитесь, что 'deleted' не конфликтует с существующими ключами настроений.

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'user_id' => $userId,
            'entry_date' => $date
        ]);
        
        // Уменьшаем счётчик записей пользователя, если запись была удалена
        // (это имеет смысл, если вы используете user_entriestotal как счётчик видимых записей)
        // if ($stmt->rowCount() > 0) {
        //     $updateUserStmt = $pdo->prepare("UPDATE calendar_users SET user_entriestotal = GREATEST(0, user_entriestotal - 1) WHERE user_id = :user_id");
        //     $updateUserStmt->execute(['user_id' => $userId]);
        // }

        if ($stmt->rowCount() > 0) {
             echo json_encode(['success' => true, 'message' => 'Запись успешно скрыта/удалена.']);
        } else {
             // Запись не найдена или уже удалена
             echo json_encode(['success' => true, 'message' => 'Запись не найдена или уже была скрыта.']);
             // Можно вернуть 404, если хотите строго различать "успех" и "не найдено"
             // http_response_code(404);
             // echo json_encode(['success' => false, 'message' => 'Запись не найдена.']);
             // exit;
        }

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ошибка скрытия/удаления в БД: ' . $e->getMessage()]);
        exit;
    }
}

// --- Маршрутизация запросов ---
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json'); // Все ответы будут в JSON

switch ($method) {
    case 'GET':
        getCalendarData($pdo, $bot_token);
        break;
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        if(isset($input['mode']) && $input['mode'] == "adminStats"){
            
        } else if (isset($input['mode']) && $input['mode'] == "saveImage") {
        // Обработка сохранения изображения
        try {
            $result = saveUserImage($pdo, $bot_token, $input);
            if ($result['success'] && isset($result['url'])) {
                require_once __DIR__ . '/telegram.php';
                $delivery = (new TelegramBot($bot_token))->sendPhotoBestEffort(
                    $input['user_id'],
                    $result['path'] ?? '',
                    $result['url'],
                    'Ваше изображение календаря готово!',
                    ['parse_mode' => 'HTML']
                );
                $result['telegram_sent'] = $delivery;
            }
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(400);
            Log::warn('tg', 'Сохранение и отправка картинки не удались', ['ошибка' => $e->getMessage()]);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    } else if (isset($input['mode']) && $input['mode'] == "hideEntry") { // Убедитесь, что 'hideEntry' совпадает с mode в JS
        try {
            hideCalendarEntry($pdo, $bot_token, $input);
        } catch (Exception $e) {
            // Если hideCalendarEntry выбрасывает исключение, оно будет поймано здесь
            http_response_code(500); // Или другой подходящий код
            echo json_encode(['success' => false, 'message' => 'Ошибка обработки запроса на скрытие: ' . $e->getMessage()]);
        }
        exit; // Важно выйти после обработки
    // --- КОНЕЦ НОВОГО БЛОКА ---
    } else {
            saveCalendarEntry($pdo, $bot_token, $input);
        }
        break;
    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
        break;
}
?>