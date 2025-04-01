<?php

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
 * Получение данных календаря для пользователя за указанный год.
 */
function getCalendarData($pdo) {
    if (!isset($_GET['year']) || !isset($_GET['user_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'Параметры year и user_id обязательны']);
        exit;
    }

    $year = filter_var($_GET['year'], FILTER_VALIDATE_INT);
    $userId = filter_var($_GET['user_id'], FILTER_VALIDATE_INT); // Или FILTER_VALIDATE_FLOAT если ID очень большие

    if ($year === false || $userId === false || $userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректные параметры year или user_id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT entry_date, mood_key, description FROM calendar_entries WHERE user_id = :user_id AND YEAR(entry_date) = :year ORDER BY entry_date ASC");
        $stmt->execute(['user_id' => $userId, 'year' => $year]);
        $results = $stmt->fetchAll();

        // Форматируем результат для JavaScript (плоский объект date => data)
        $formattedData = [];
        foreach ($results as $row) {
            $formattedData[$row['entry_date']] = [
                'color' => $row['mood_key'],
                'description' => $row['description'] ?? '' // Гарантируем строку, если description NULL
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
function saveCalendarEntry($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);

    // Валидация входных данных
    if (!$input || !isset($input['user_id'], $input['date'], $input['mood_key'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неполные данные. Требуются user_id, date, mood_key.']);
        exit;
    }

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT); // Или FILTER_VALIDATE_FLOAT
    $date = $input['date']; // Дополнительно можно валидировать формат YYYY-MM-DD
    $moodKey = trim($input['mood_key']);
    $description = isset($input['description']) ? trim($input['description']) : null; // Описание опционально

    // Простая валидация (добавьте более строгую при необходимости)
    if ($userId === false || $userId <= 0 || empty($moodKey) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
         http_response_code(400);
         echo json_encode(['success' => false, 'message' => 'Некорректные данные. Проверьте user_id, date (YYYY-MM-DD), mood_key.']);
         exit;
    }
    // Тут можно добавить проверку moodKey на допустимые значения

    try {
        // Используем INSERT ... ON DUPLICATE KEY UPDATE для "upsert"
        $sql = "INSERT INTO calendar_entries (user_id, entry_date, mood_key, description)
                VALUES (:user_id, :entry_date, :mood_key, :description)
                ON DUPLICATE KEY UPDATE
                   mood_key = VALUES(mood_key),
                   description = VALUES(description)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'entry_date' => $date,
            'mood_key' => $moodKey,
            'description' => $description
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
        getCalendarData($pdo);
        break;
    case 'POST':
        saveCalendarEntry($pdo);
        break;
    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
        break;
}

?>