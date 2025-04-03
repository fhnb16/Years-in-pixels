<?

// --- Настройки CORS (Cross-Origin Resource Sharing) ---
// Важно для локальной разработки или если фронтенд на другом домене/порту
header("Access-Control-Allow-Origin: *"); // В продакшене замените * на ваш домен фронтенда
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// --- Конфигурация Базы Данных ---
$db_host = 'localhost';     // Или ваш хост БД
$db_user = 'your_db_user';  // Ваше имя пользователя БД
$db_pass = 'your_db_password'; // Ваш пароль БД
$db_name = 'your_db_name';    // Имя вашей БД

$botToken = "";

?>