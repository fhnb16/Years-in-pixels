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
        error_log("Secret Key (bin2hex): " . $secretKey);
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
    //$initData = $_GET['init_data'];
    $initData = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($year === false || $userId === false || $userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректные параметры year или user_id']);
        exit;
    }

    // Проверка hash
    if (!checkHash($initData, $botToken, $userId)) {
        http_response_code(403); // Forbidden
        error_log("ERROR: initData check failed. initData:" . $initData); // add log for initData
        echo json_encode(['success' => false, 'message' => 'Не удалось проверить initData. Данные подделаны или устарели.']);
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
function saveCalendarEntry($pdo, $botToken) {
    $input = json_decode(file_get_contents('php://input'), true);

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
    //$initData = $input['init_data'];
    $initData = $_SERVER['HTTP_AUTHORIZATION'] ?? '';


    // Простая валидация (добавьте более строгую при необходимости)
    if ($userId === false || $userId <= 0 || empty($moodKey) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
         http_response_code(400);
         echo json_encode(['success' => false, 'message' => 'Некорректные данные. Проверьте user_id, date (YYYY-MM-DD), mood_key.']);
         exit;
    }
    
    // Проверка hash
    if (!checkHash($initData, $botToken, $userId)) {
        http_response_code(403); // Forbidden
        error_log("ERROR: initData check failed. initData:" . $initData); // add log for initData
        echo json_encode(['success' => false, 'message' => 'Не удалось проверить initData. Данные подделаны или устарели.']);
        exit;
    }
    // Тут можно добавить проверку moodKey на допустимые значения

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
        saveCalendarEntry($pdo, $bot_token);
        break;
    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
        break;
}

?>