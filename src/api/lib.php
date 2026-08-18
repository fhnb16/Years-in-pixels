<?php
/**
 * Общая обвязка: подключение к БД, структурный журнал, настройки, проверка Telegram-подписи.
 * Подключается из index.php, admin.php, setup.php, telegram.php.
 */

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------- база

function db(): PDO
{
    static $pdo = null, $failed = null;
    if ($pdo !== null)    return $pdo;
    if ($failed !== null) throw $failed;   // не пытаемся подключиться повторно в том же запросе: это удвоенный таймаут

    global $db_host, $db_name, $db_user, $db_pass;
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        $failed = $e;
        Log::error('db', 'Не удалось подключиться к базе', [
            'хост' => $db_host, 'база' => $db_name, 'пользователь' => $db_user,
            'ошибка' => $e->getMessage(),
        ]);
        throw $e;
    }
    return $pdo;
}

/**
 * Ни один ответ с ошибкой не уходит молча: даже ранний exit и фатальная ошибка PHP
 * оставляют строку в журнале. Это ровно та дыра, из-за которой отладка вебхуков
 * превращается в неделю гадания.
 */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Log::error('api', 'Фатальная ошибка PHP', [
            'текст' => $err['message'], 'файл' => basename($err['file']), 'строка' => $err['line'],
        ]);
        return;
    }
    $code = http_response_code();
    if (is_int($code) && $code >= 400) {
        Log::warn('api', "Ответ $code", [
            'метод' => $_SERVER['REQUEST_METHOD'] ?? '?',
            'путь'  => strtok($_SERVER['REQUEST_URI'] ?? '', '?'),
            'ip'    => $_SERVER['REMOTE_ADDR'] ?? '?',
            'через_реле' => $_SERVER['HTTP_X_FORWARDED_VIA'] ?? 'нет',
        ]);
    }
});

// -------------------------------------------------------------- журнал

class Log
{
    const LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

    public static function dir(): string { return __DIR__ . '/logs'; }

    public static function debug($cat, $msg, $ctx = []) { self::write('debug', $cat, $msg, $ctx); }
    public static function info($cat, $msg, $ctx = [])  { self::write('info',  $cat, $msg, $ctx); }
    public static function warn($cat, $msg, $ctx = [])  { self::write('warn',  $cat, $msg, $ctx); }
    public static function error($cat, $msg, $ctx = []) { self::write('error', $cat, $msg, $ctx); }

    /** Идентификатор запроса: связывает все записи одного обращения. */
    public static function rid(): string
    {
        static $rid = null;
        if ($rid === null) $rid = bin2hex(random_bytes(4));
        return $rid;
    }

    public static function write(string $lvl, string $cat, string $msg, array $ctx = []): void
    {
        if ((self::LEVELS[$lvl] ?? 1) < self::minLevel()) return;

        $dir = self::dir();
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $file    = $dir . '/' . date('Y-m-d') . '.jsonl';
        $isFirst = !file_exists($file);

        $line = json_encode([
            't'   => date('Y-m-d\TH:i:sP'),
            'lvl' => $lvl,
            'cat' => $cat,
            'msg' => $msg,
            'rid' => self::rid(),
            'src' => basename($_SERVER['SCRIPT_NAME'] ?? 'cli'),
            'ctx' => $ctx,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        @file_put_contents($file, self::redact($line) . "\n", FILE_APPEND | LOCK_EX);

        // Ротация раз в сутки — при создании файла нового дня, а не на каждой записи.
        if ($isFirst) self::rotate($dir);
    }

    /** Вырезает секреты из готовой строки: дешевле и надёжнее обхода вложенных массивов. */
    private static function redact(string $line): string
    {
        global $bot_token, $db_pass;
        $secrets = array_filter([
            $bot_token ?? '', $db_pass ?? '',
            Settings::get('socks_pass', ''), Settings::get('socks2_pass', ''),
            Settings::get('wh_secret', ''),
        ], fn($s) => strlen((string)$s) >= 6);

        foreach ($secrets as $s) $line = str_replace((string)$s, '***', $line);
        return $line;
    }

    private static function minLevel(): int
    {
        static $lvl = null;
        if ($lvl === null) {
            $lvl = 1;                                  // до чтения настроек считаем info — рекурсия исключена
            $lvl = self::LEVELS[Settings::get('log_level', 'info')] ?? 1;
        }
        return $lvl;
    }

    private static function rotate(string $dir): void
    {
        $keep = max(1, (int)Settings::get('log_keep_days', 14));
        $edge = time() - $keep * 86400;
        foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
            if (filemtime($f) < $edge) @unlink($f);
        }
    }

    /** Чтение хвоста журнала с фильтрами. Возвращает массив разобранных записей, новые сверху. */
    public static function tail(string $date, int $limit = 200, array $filters = []): array
    {
        $file = self::dir() . '/' . preg_replace('/[^0-9-]/', '', $date) . '.jsonl';
        if (!is_file($file)) return [];

        $out = [];
        $fh  = fopen($file, 'r');
        if (!$fh) return [];
        while (($line = fgets($fh)) !== false) {
            $rec = json_decode($line, true);
            if (!is_array($rec)) continue;
            if (!empty($filters['lvl']) && (self::LEVELS[$rec['lvl']] ?? 0) < (self::LEVELS[$filters['lvl']] ?? 0)) continue;
            if (!empty($filters['cat']) && $rec['cat'] !== $filters['cat']) continue;
            if (!empty($filters['q']) && mb_stripos($line, $filters['q']) === false) continue;
            $out[] = $rec;
            if (count($out) > $limit * 4) $out = array_slice($out, -$limit);  // ponytail: держим хвост в памяти, не индексируем файл
        }
        fclose($fh);
        return array_reverse(array_slice($out, -$limit));
    }
}

// ------------------------------------------------------------ настройки

class Settings
{
    private static $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        self::$cache = [];                              // ставим до запроса: db() при падении пишет в журнал, тот читает настройки
        try {
            foreach (db()->query('SELECT k, v FROM app_settings') as $r) self::$cache[$r['k']] = $r['v'];
        } catch (Throwable $e) {
            // Таблицы ещё нет или база недоступна — работаем на умолчаниях из settings_spec().
        }
        return self::$cache;
    }

    public static function get(string $k, $default = null)
    {
        $all = self::all();
        if (isset($all[$k]) && $all[$k] !== '') return $all[$k];
        if ($default !== null) return $default;
        return settings_defaults()[$k] ?? null;
    }

    public static function int(string $k, int $default = 0): int   { return (int)self::get($k, (string)$default); }
    public static function bool(string $k, bool $default = false): bool { return self::get($k, $default ? '1' : '0') === '1'; }
    public static function list(string $k, array $default = []): array
    {
        $v = self::get($k, implode(',', $default));
        return array_values(array_filter(array_map('trim', explode(',', (string)$v))));
    }

    public static function set(string $k, string $v): void
    {
        db()->prepare('INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute([$k, $v]);
        self::$cache[$k] = $v;
    }
}

/**
 * Единственное описание всех настроек: из него растут и установщик, и форма в панели.
 * Секреты уровня «доступ ко всему» (токен бота, пароль БД, пароль админа) сюда не попадают —
 * они живут в config.php, чтобы взлом базы не давал доступа к панели.
 */
function settings_spec(): array
{
    return [
        'Каналы связи с Telegram' => [
            'tg_order' => ['Порядок каналов', 'order', 'worker,direct,socks,socks2',
                'Через что пробовать достучаться до Bot API, по очереди сверху вниз. Выключенные каналы не пробуются вовсе.'],
            'tg_api_base' => ['Адрес Bot API', 'text', 'https://api.telegram.org',
                'Для каналов «напрямую» и «через SOCKS». Менять почти никогда не нужно.'],
            'tg_worker_base' => ['Адрес реле Cloudflare', 'text', '',
                'Ваш воркер, который переправляет запросы в Telegram. Без завершающего слэша, например https://tg.example.workers.dev'],
            'tg_connect_timeout' => ['Таймаут подключения, с', 'number', '10',
                'Сколько ждать установления соединения, прежде чем перейти к следующему каналу.'],
            'tg_total_timeout' => ['Общий таймаут, с', 'number', '20',
                'Потолок на весь запрос вместе с передачей файла.'],
            'tg_doh_enabled' => ['Резолвить через DNS-over-HTTPS', 'check', '1',
                'Помогает, когда провайдер подменяет ответы обычного DNS. Работает только для каналов без прокси.'],
            'tg_doh_url' => ['Адрес DoH', 'text', 'https://cloudflare-dns.com/dns-query',
                'Альтернативы: https://dns.google/dns-query, https://doh.umbrella.com/dns-query'],
        ],
        'Основной SOCKS5' => [
            'socks_host' => ['Хост', 'text', '', ''],
            'socks_port' => ['Порт', 'number', '1080', ''],
            'socks_user' => ['Логин', 'text', '', ''],
            'socks_pass' => ['Пароль', 'password', '', 'Хранится в базе. Токен бота и пароль БД — нет, они в config.php.'],
        ],
        'Запасной SOCKS5' => [
            'socks2_host' => ['Хост', 'text', '', ''],
            'socks2_port' => ['Порт', 'number', '1080', ''],
            'socks2_user' => ['Логин', 'text', '', ''],
            'socks2_pass' => ['Пароль', 'password', '', ''],
        ],
        'Входящие вебхуки' => [
            'wh_url' => ['Адрес для setWebhook', 'text', '',
                'Что увидит Telegram. Обычно это эндпоинт реле Cloudflare, а не ваш сервер напрямую.'],
            'wh_secret' => ['Секрет вебхука', 'password', '',
                'Telegram шлёт его в заголовке X-Telegram-Bot-Api-Secret-Token. Реле обязано пробрасывать заголовок дальше без изменений.'],
            'wh_local_url' => ['Наш эндпоинт за реле', 'text', '',
                'Куда реле переправляет обновления. Нужен только для кнопки проверки — панель дёргает его сама и показывает ответ.'],
        ],
        'Журнал' => [
            'log_level' => ['Минимальный уровень', 'select:debug,info,warn,error', 'info',
                'debug пишет каждый запрос к API и полезен день-два, потом раздувает файлы.'],
            'log_keep_days' => ['Хранить дней', 'number', '14',
                'Файлы старше удаляются при создании журнала нового дня.'],
        ],
    ];
}

function settings_defaults(): array
{
    $out = [];
    foreach (settings_spec() as $fields) {
        foreach ($fields as $k => $f) $out[$k] = $f[2];
    }
    return $out;
}

// ------------------------------------------------- подпись Telegram WebApp

/** Приводит initData к строке для подписи и возвращает [полученный hash, строка]. */
function convertInitData(string $initData): array
{
    $items = explode('&', rawurldecode($initData));
    $hash  = '';
    foreach ($items as &$data) {
        if (strpos($data, 'hash=') === 0) {
            $hash = substr($data, 5);
            $data = null;
        }
    }
    unset($data);
    $items = array_filter($items);
    sort($items);
    return [$hash, implode("\n", $items)];
}

function parseInitData(string $initData): array
{
    $params = [];
    foreach (explode('&', rawurldecode($initData)) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) === 2) $params[$parts[0]] = $parts[1];
    }
    return $params;
}

function checkHash(string $initData, string $botToken): bool
{
    [$checksum, $sorted] = convertInitData($initData);
    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $hash      = bin2hex(hash_hmac('sha256', $sorted, $secretKey, true));

    if (!hash_equals($hash, $checksum)) {
        // Ни initData, ни ключ в журнал не попадают: по ним подделывается запрос.
        Log::warn('auth', 'Подпись initData не совпала', [
            'полей_в_данных'  => count(explode("\n", $sorted)),
            'длина_подписи'   => strlen($checksum),
            'подпись_получена' => $checksum === '' ? 'нет поля hash' : 'да',
            'ip'              => $_SERVER['REMOTE_ADDR'] ?? '?',
        ]);
        return false;
    }
    return true;
}

/** Возраст подписи в секундах: Telegram кладёт auth_date в initData. */
function initDataAge(string $initData): ?int
{
    $d = parseInitData($initData);
    return isset($d['auth_date']) ? time() - (int)$d['auth_date'] : null;
}

// ------------------------------------------------------- доступ в панель

function admin_boot(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    session_name('yipadm');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function admin_user(): ?array
{
    admin_boot();
    return $_SESSION['admin'] ?? null;
}

function admin_csrf(): string
{
    admin_boot();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function admin_check_csrf(): bool
{
    admin_boot();
    $sent = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$sent);
}

/** Вход по паролю из config.php. Пауза при неудаче — простейшая защита от перебора. */
function admin_login_password(string $password): array
{
    global $admin_password_hash;
    admin_boot();
    if (empty($admin_password_hash)) {
        Log::warn('admin', 'Вход по паролю отклонён: пароль не настроен', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
        return [false, 'Пароль не задан. Добавьте $admin_password_hash в config.php'];
    }
    if (!password_verify($password, $admin_password_hash)) {
        Log::warn('admin', 'Вход по паролю отклонён: пароль не подошёл', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '?', 'длина_введённого' => strlen($password),
        ]);
        usleep(700000);
        return [false, 'Пароль не подошёл'];
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = ['via' => 'пароль', 'uid' => null, 'name' => 'по паролю', 'since' => time()];
    Log::info('admin', 'Вход в панель по паролю', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    return [true, ''];
}

/** Вход из Telegram: подпись валидна, не протухла, и user_id в списке админов. */
function admin_login_initdata(string $initData): array
{
    global $bot_token, $admin_ids;
    admin_boot();

    if (!checkHash($initData, (string)$bot_token)) return [false, 'Подпись Telegram не совпала'];

    $age = initDataAge($initData);
    if ($age === null || $age > 86400) {
        Log::warn('admin', 'Вход из Telegram отклонён: подпись устарела', ['возраст_с' => $age]);
        return [false, 'Данные Telegram устарели, откройте панель из бота заново'];
    }

    $user = json_decode(parseInitData($initData)['user'] ?? '', true);
    $uid  = (int)($user['id'] ?? 0);
    if (!$uid || !in_array($uid, (array)($admin_ids ?? []), false)) {
        Log::warn('admin', 'Вход из Telegram отклонён: пользователь не админ', [
            'user_id' => $uid ?: 'не разобрался',
            'админов_в_списке' => count((array)($admin_ids ?? [])),
        ]);
        return [false, 'Этот аккаунт не в списке администраторов'];
    }

    session_regenerate_id(true);
    $_SESSION['admin'] = [
        'via'   => 'Telegram',
        'uid'   => $uid,
        'name'  => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ('id ' . $uid),
        'since' => time(),
    ];
    Log::info('admin', 'Вход в панель из Telegram', ['user_id' => $uid]);
    return [true, ''];
}

function admin_logout(): void
{
    admin_boot();
    Log::info('admin', 'Выход из панели', ['кто' => $_SESSION['admin']['name'] ?? '?']);
    $_SESSION = [];
    session_destroy();
}
