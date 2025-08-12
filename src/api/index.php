<?

include_once("config.php");

// Обработка preflight запроса OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// --- Подключение к Базе Данных (PDO) ---
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Ошибка подключения к базе данных: ' . $e->getMessage()]);
    exit;
}

// --- Функции API ---

/**
 * Проверяет hash, полученный от Telegram Web App.
 * 
 * @param string $initData Строка initData, полученная от Telegram.
 * @param string $botToken Токен вашего бота.
 * @return bool Возвращает true, если hash валиден, иначе false.
 */
function checkHash(string $initData, string $botToken): bool
{
    [$checksum, $sortedInitData] = convertInitData($initData);
    $secretKey                   = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $hash                        = bin2hex(hash_hmac('sha256', $sortedInitData, $secretKey, true));
    
    if(0 !== strcmp($hash, $checksum)){
        error_log("Init Data: " . $initData);
        error_log("Data Check String: " . $sortedInitData);
        error_log("Secret Key (bin2hex): " . bin2hex($secretKey));
        error_log("Calculated Hash: " . $hash);
        error_log("Received Hash: " . $checksum);
        return false;
    }

    return true;
}

/**
 * convert init data to `key=value` and sort it `alphabetically`.
 *
 * @param string $initData init data from Telegram (`Telegram.WebApp.initData`)
 *
 * @return string[] return hash and sorted init data
 */
function convertInitData(string $initData): array
{
    $initDataArray = explode('&', rawurldecode($initData));
    $needle        = 'hash=';
    $hash          = '';

    foreach ($initDataArray as &$data) {
        if (substr($data, 0, \strlen($needle)) === $needle) {
            $hash = substr_replace($data, '', 0, \strlen($needle));
            $data = null;
        }
    }
    $initDataArray = array_filter($initDataArray);
    sort($initDataArray);

    return [$hash, implode("\n", $initDataArray)];
}

/**
 * Парсит initData и возвращает массив данных пользователя
 */
function parseInitData(string $initData): array
{
    $params = [];
    $pairs = explode('&', rawurldecode($initData));
    
    foreach ($pairs as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) == 2) {
            $params[$parts[0]] = $parts[1];
        }
    }
    
    return $params;
}

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

        // Генерируем публичный URL
        // Определяем базовый URL сайта
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $baseUrl = $protocol . '://' . $host;
        
        // Получаем путь к скрипту относительно корня сайта
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        if ($scriptPath === '/') {
            $scriptPath = '';
        }
        
        $publicUrl = $baseUrl . $scriptPath . '/user_screens/' . $filename;

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
 * Отправка изображения пользователю через Telegram
 */
function sendImageToUser($pdo, $botToken, $input, $caption = null) {
    // Валидация входных данных
    if (!$input || !isset($input['user_id'], $input['image_url'], $_SERVER['HTTP_AUTHORIZATION'])) {
        throw new Exception('Неполные данные. Требуются user_id и image_url.');
    }

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $imageUrl = $input['image_url'];
    $initData = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $caption = $input['caption'] ?? 'Ваше изображение календаря';

    if ($userId === false || $userId <= 0) {
        throw new Exception('Некорректный user_id');
    }

    if (empty($imageUrl)) {
        throw new Exception('Не указан URL изображения');
    }

    // Проверка hash
    if (!checkHash($initData, $botToken)) {
        throw new Exception('Неверный hash initData');
    }

    try {
        // Подключаем Telegram API
        include_once("telegram.php");
        
        $telegram = new TelegramBot($botToken);
        $result = $telegram->sendPhoto($userId, $imageUrl, $caption);
        
        // Логируем успешную отправку
        $logStmt = $pdo->prepare("INSERT INTO calendar_users_log (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
        try {
            $logStmt->execute([
                'user_id' => $userId,
                'action' => 'image_sent',
                'details' => json_encode(['image_url' => $imageUrl, 'caption' => $caption])
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }
        
        return [
            'success' => true,
            'message' => 'Изображение успешно отправлено',
            'telegram_result' => $result
        ];

    } catch (Exception $e) {
        error_log("Ошибка отправки изображения пользователю {$userId}: " . $e->getMessage());
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
        error_log("Geolocation error for user {$userId}: " . $e->getMessage());
        // Не прерываем основной процесс из-за ошибки геолокации
        // Пытаемся обновить хотя бы IP
        try {
            $ip = getUserIP();
            if ($ip) {
                $updateIpStmt = $pdo->prepare("UPDATE calendar_users SET user_latestip = :ip WHERE user_id = :user_id");
                $updateIpStmt->execute(['user_id' => $userId, 'ip' => $ip]);
            }
        } catch (Exception $e2) {
            error_log("IP update error for user {$userId}: " . $e2->getMessage());
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
        error_log("ERROR: User upsert failed. " . $e->getMessage());
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
        error_log("ERROR: User upsert failed. " . $e->getMessage());
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
                try {
                    include_once("telegram.php");
                    $telegram = new TelegramBot($bot_token);
                    $sendResult = $telegram->sendPhoto($input['user_id'], $result['url']);
                    $sendResult = $telegram->sendMessage($input['user_id'], "Ваше изображение календаря готово!");
                    $result['telegram_sent'] = ['success' => true, 'result' => $sendResult];
                } catch (Exception $e) {
                    error_log("Ошибка отправки изображения: " . $e->getMessage());
                    $result['telegram_sent'] = ['success' => false, 'error' => $e->getMessage()];
                }
            }
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
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