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

$bot_token = "";

    $connectTimeout = 6; // 5-7 секунд, как просил
    $totalTimeout = 15;
    $dohUrl = 'https://cloudflare-dns.com/dns-query'; // или https://dns.google/dns-query
    
    // Прокси-настройки (вынеси в конфиг!)
    $proxyConfig = [
        'host' => '127.0.0.1',
        'port' => 1080,
        'user' => 'your_proxy_user',
        'pass' => 'your_proxy_pass',
    ];

?>