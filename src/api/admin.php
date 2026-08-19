<?php
/**
 * Панель управления: настройки, диагностика каналов Telegram, журнал, календари пользователей.
 * Один файл, PHP + ванильный JS, без сборки.
 *
 * Правило страницы: при отрисовке не делается ни одного внешнего запроса.
 * Всё, что ходит в сеть, запускается кнопкой из JS — иначе вкладка висит до таймаута,
 * а именно в тот момент, когда канал до Telegram и лежит.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/setup.php';

admin_boot();
$a = $_GET['a'] ?? '';

function jout($x) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
function jin(): array { $d = json_decode(file_get_contents('php://input'), true); return is_array($d) ? $d : []; }

// -------------------------------------------------------------- установка

$installMode = !app_installed();

if ($installMode && strpos($a, 'inst_') === 0) {
    if (!admin_check_csrf()) jout(['ok' => false, 'message' => 'Сессия устарела, обновите страницу']);
    if ($a !== 'inst_save') session_write_close();     // сетевые проверки не должны держать блокировку сессии
    try {
        jout(installer_action($a, jin()));
    } catch (Throwable $e) {
        Log::error('admin', 'Установщик упал', ['шаг' => $a, 'ошибка' => $e->getMessage()]);
        jout(['ok' => false, 'message' => $e->getMessage()]);
    }
}

function installer_action(string $a, array $in): array
{
    switch ($a) {

        // Проверяем не только вход, но и право создавать таблицы: иначе установка «пройдёт»,
        // а всё встанет на первом же CREATE TABLE.
        case 'inst_db': {
            $t0  = microtime(true);
            $cur = config_current();
            // Пустой пароль означает «оставить прежний»: в разметку он не выводится, вводить заново незачем.
            $pass = ($in['db_pass'] ?? '') !== '' ? (string)$in['db_pass'] : $cur['db_pass'];
            try {
                $pdo = new PDO(
                    'mysql:host=' . (string)($in['db_host'] ?? '') . ';dbname=' . (string)($in['db_name'] ?? '') . ';charset=utf8mb4',
                    (string)($in['db_user'] ?? ''), $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 6]
                );
            } catch (PDOException $e) {
                $m = $e->getMessage();
                $hint = strpos($m, 'to database') !== false || strpos($m, 'Unknown database') !== false
                      ? 'База с таким именем либо не существует, либо этому пользователю не выдали на неё прав.'
                      : (strpos($m, 'Access denied') !== false ? 'Пользователь или пароль не подошли.'
                      : (strpos($m, '2002') !== false ? 'Сервер базы не отвечает по этому адресу. На шаред-хостинге это обычно localhost.' : ''));
                return ['ok' => true, 'check' => ['name' => 'Подключение к базе', 'status' => 'err', 'detail' => $m, 'hint' => $hint]];
            }
            try {
                $pdo->exec('CREATE TABLE IF NOT EXISTS yip_install_check (id INT)');
                $pdo->exec('DROP TABLE IF EXISTS yip_install_check');
            } catch (PDOException $e) {
                return ['ok' => true, 'check' => ['name' => 'Подключение к базе', 'status' => 'err',
                    'detail' => 'Вход есть, но таблицы создавать нельзя: ' . $e->getMessage(),
                    'hint'   => 'Выдайте пользователю права CREATE и ALTER на эту базу.']];
            }
            $ms    = (int)round((microtime(true) - $t0) * 1000);
            $found = [];
            foreach (['calendar_users' => 'пользователей', 'calendar_entries' => 'записей'] as $t => $what) {
                try {
                    if ($pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->fetchColumn()) {
                        $found[] = $what . ': ' . (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                    }
                } catch (PDOException $e) { /* таблицы может не быть — это нормально */ }
            }
            return ['ok' => true, 'check' => ['name' => 'Подключение к базе', 'status' => 'ok',
                'detail' => 'вход есть, таблицы создавать можно — ' . $ms . ' мс'
                          . ($found ? '. Данные на месте — ' . implode(', ', $found) : '. База пустая, таблицы создадутся сами'),
                'hint'   => $found ? 'Установщик только добавляет недостающее: существующие таблицы, столбцы и данные не трогаются.' : '']];
        }

        // Токен проверяем сразу двумя путями: напрямую и через реле, если адрес указан.
        case 'inst_bot': {
            $token = trim((string)($in['bot_token'] ?? '')) ?: config_current()['bot_token'];
            $relay = trim((string)($in['relay'] ?? ''));
            if ($token === '') return ['ok' => false, 'message' => 'Впишите токен бота'];

            $checks = [];
            foreach (array_filter([
                ['direct', 'Напрямую', 'https://api.telegram.org'],
                $relay !== '' ? ['worker', 'Через реле Cloudflare', $relay] : null,
            ]) as [$ch, $name, $base]) {
                $r = tg_attempt($ch, 'getMe', [], $token, $base);
                $checks[] = ['name' => $name, 'status' => $r['ok'] ? 'ok' : 'err',
                    'detail' => $r['ok'] ? '@' . ($r['data']['result']['username'] ?? '?') . ' — ' . $r['ms'] . ' мс' : $r['message'],
                    'hint'   => $r['ok'] ? '' : tg_hint($r['reason'])];
            }
            if ($relay === '') {
                $checks[] = ['name' => 'Реле Cloudflare', 'status' => 'warn', 'detail' => 'адрес не указан',
                    'hint' => 'Если Telegram у вас блокируется, впишите адрес реле — установка без него пройдёт, но бот работать не будет.'];
            }
            return ['ok' => true, 'checks' => $checks];
        }

        case 'inst_save': {
            $cur = config_current();
            $ids = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)($in['admin_ids'] ?? '')))));
            $pw  = (string)($in['password'] ?? '');
            $pw2 = (string)($in['password2'] ?? '');

            if ($pw !== '' && $pw !== $pw2)      return ['ok' => false, 'message' => 'Пароли не совпали'];
            if ($pw !== '' && strlen($pw) < 8)   return ['ok' => false, 'message' => 'Пароль короче 8 символов'];
            if ($pw === '' && !$ids)             return ['ok' => false, 'message' => 'Задайте пароль или хотя бы один Telegram ID — иначе в панель не войти'];

            $origins = array_values(array_filter(array_map('trim', explode("\n", str_replace(',', "\n", (string)($in['origins'] ?? ''))))));

            // Пустое поле = «оставить как было». Пароль базы и токен в разметку не выводятся,
            // поэтому при обновлении их вводить заново не нужно.
            $keep = fn(string $k, string $was) => trim((string)($in[$k] ?? '')) !== '' ? trim((string)$in[$k]) : $was;

            [$ok, $msg, $text] = config_write([
                'db_host' => $keep('db_host', $cur['db_host']),
                'db_user' => $keep('db_user', $cur['db_user']),
                'db_pass' => ($in['db_pass'] ?? '') !== '' ? (string)$in['db_pass'] : $cur['db_pass'],
                'db_name' => $keep('db_name', $cur['db_name']),
                'bot_token' => $keep('bot_token', $cur['bot_token']),
                'admin_ids' => $ids,
                'admin_password_hash' => $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : $cur['admin_password_hash'],
                'allowed_origins' => $origins ?: $cur['allowed_origins'],
            ]);
            if (!$ok) return ['ok' => false, 'message' => $msg, 'manual' => $text];

            $report = setup_run();

            // Адрес реле из установщика — сразу в настройки, первым каналом.
            $relay = trim((string)($in['relay'] ?? ''));
            if ($relay !== '') {
                try {
                    Settings::set('tg_worker_base', rtrim($relay, '/'));
                    Settings::set('tg_order', 'worker,direct,socks,socks2');
                } catch (Throwable $e) { /* схема могла не создаться — об этом уже сказано в отчёте */ }
            }

            // Сразу впускаем того, кто ставил: второй раз пароль вводить незачем.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
                $_SESSION['admin'] = ['via' => 'установщик', 'uid' => $ids[0] ?? null, 'name' => 'установщик', 'since' => time()];
            }
            Log::info('admin', 'Установка завершена', ['админов' => count($ids), 'пароль' => $pw !== '' ? 'задан' : 'не задан', 'реле' => $relay !== '']);

            $map = ['ok' => 'ok', 'added' => 'ok', 'warn' => 'warn', 'err' => 'err'];
            return ['ok' => true, 'message' => 'Установка завершена',
                'checks' => array_map(fn($s) => ['name' => $s['what'], 'status' => $map[$s['status']] ?? 'warn',
                    'detail' => ($s['status'] === 'added' ? '✚ ' : '') . $s['detail'], 'hint' => ''], $report['steps'])];
        }
    }
    return ['ok' => false, 'message' => 'Неизвестный шаг установки'];
}

// ------------------------------------------------------------ вход/выход

if ($a === 'login') {
    if (!admin_check_csrf()) jout(['ok' => false, 'message' => 'Сессия устарела, обновите страницу']);
    $in = jin();
    [$ok, $msg] = ($in['mode'] ?? '') === 'tg'
        ? admin_login_initdata((string)($in['initData'] ?? ''))
        : admin_login_password((string)($in['password'] ?? ''));
    jout(['ok' => $ok, 'message' => $msg]);
}

if ($a === 'logout') { admin_logout(); header('Location: admin.php'); exit; }

// ---------------------------------------------------------- AJAX-действия

if ($a !== '') {
    if (!admin_user())       jout(['ok' => false, 'message' => 'Нужен вход в панель']);
    if (!admin_check_csrf()) jout(['ok' => false, 'message' => 'Проверка CSRF не прошла, обновите страницу']);

    // Отпускаем файл сессии до долгих сетевых вызовов: иначе соседние запросы той же
    // вкладки ждут блокировку и панель выглядит зависшей целиком.
    session_write_close();

    try {
        jout(admin_action($a, jin()));
    } catch (Throwable $e) {
        Log::error('admin', 'Действие панели упало', ['действие' => $a, 'ошибка' => $e->getMessage()]);
        jout(['ok' => false, 'message' => $e->getMessage()]);
    }
}

function admin_action(string $a, array $in): array
{
    switch ($a) {

        // --- сохранение настроек
        case 'save': {
            $allowed = settings_defaults();
            $changed = [];
            foreach ((array)($in['values'] ?? []) as $k => $v) {
                if (!array_key_exists($k, $allowed)) continue;
                if ((string)Settings::get($k, '') === (string)$v) continue;
                Settings::set($k, (string)$v);
                $changed[] = $k;
            }
            Log::info('admin', 'Настройки сохранены', ['ключи' => $changed]);   // значения не пишем: среди них пароли
            return ['ok' => true, 'message' => $changed ? 'Сохранено: ' . count($changed) . ' шт.' : 'Изменений не было'];
        }

        // --- смена пароля, списка админов, токена: правит config.php, не базу
        case 'access': {
            $cur = config_current();
            $pw  = (string)($in['password'] ?? '');
            $pw2 = (string)($in['password2'] ?? '');
            if ($pw !== '' && $pw !== $pw2)    return ['ok' => false, 'message' => 'Пароли не совпали'];
            if ($pw !== '' && strlen($pw) < 8) return ['ok' => false, 'message' => 'Пароль короче 8 символов'];

            $ids = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)($in['admin_ids'] ?? '')))));
            $org = array_values(array_filter(array_map('trim', explode(',', (string)($in['origins'] ?? '')))));
            $tok = trim((string)($in['bot_token'] ?? ''));
            if ($pw === '' && !$ids)           return ['ok' => false, 'message' => 'Нельзя убрать сразу и пароль, и всех админов — в панель будет не войти'];

            [$ok, $msg, $text] = config_write([
                'db_host' => $cur['db_host'], 'db_user' => $cur['db_user'],
                'db_pass' => $cur['db_pass'], 'db_name' => $cur['db_name'],
                'bot_token' => $tok !== '' ? $tok : $cur['bot_token'],
                'admin_ids' => $ids,
                'admin_password_hash' => $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : $cur['admin_password_hash'],
                'allowed_origins' => $org ?: $cur['allowed_origins'],
            ]);
            return ['ok' => $ok, 'message' => $ok ? ($pw !== '' ? 'Пароль изменён' : $msg) : $msg, 'manual' => $ok ? null : $text];
        }

        // --- проверка одного канала до Bot API
        case 'test': {
            $ch = (string)($in['channel'] ?? 'direct');
            if (!isset(tg_channels()[$ch])) return ['ok' => false, 'message' => 'Неизвестный канал'];
            $r = tg_attempt($ch, 'getMe');
            Log::info('admin', 'Проверка канала', ['канал' => $ch, 'успех' => $r['ok'], 'мс' => $r['ms'], 'причина' => $r['reason']]);
            return [
                'ok'      => true,
                'check'   => [
                    'name'   => tg_channels()[$ch],
                    'status' => $r['ok'] ? 'ok' : ($r['reason'] === 'not_configured' ? 'warn' : 'err'),
                    'detail' => $r['ok']
                        ? '@' . ($r['data']['result']['username'] ?? '?') . ' — ' . $r['ms'] . ' мс'
                        : $r['message'],
                    'hint'   => tg_hint($r['reason']),
                ],
            ];
        }

        // --- вебхук
        case 'webhook': {
            $op = (string)($in['op'] ?? 'info');
            $bot = new TelegramBot();
            if ($op === 'set') {
                $url = (string)Settings::get('wh_url', '');
                if ($url === '') return ['ok' => false, 'message' => 'Сначала заполните адрес для setWebhook'];
                $params = ['url' => $url, 'max_connections' => 40, 'drop_pending_updates' => false];
                if (Settings::get('wh_secret', '') !== '') $params['secret_token'] = Settings::get('wh_secret');
                $bot->makeRequest('setWebhook', $params);
                Log::info('webhook', 'Вебхук установлен', ['url' => $url, 'секрет' => Settings::get('wh_secret', '') !== '' ? 'задан' : 'НЕ ЗАДАН']);
                return ['ok' => true, 'message' => 'Вебхук установлен', 'info' => $bot->makeRequest('getWebhookInfo')['result'] ?? null];
            }
            if ($op === 'del') {
                $bot->makeRequest('deleteWebhook', ['drop_pending_updates' => false]);
                Log::warn('webhook', 'Вебхук удалён из панели');
                return ['ok' => true, 'message' => 'Вебхук удалён', 'info' => $bot->makeRequest('getWebhookInfo')['result'] ?? null];
            }
            return ['ok' => true, 'info' => $bot->makeRequest('getWebhookInfo')['result'] ?? null];
        }

        // --- жив ли наш эндпоинт за реле
        case 'inbound': {
            $url = (string)Settings::get('wh_local_url', '');
            if ($url === '') return ['ok' => false, 'message' => 'Заполните «Наш эндпоинт за реле»'];
            $t0 = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '{"ping":true}',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Telegram-Bot-Api-Secret-Token: ' . Settings::get('wh_secret', ''),
                    'X-Forwarded-Via: admin-panel-ping',
                ],
            ]);
            $body = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            $ms = (int)round((microtime(true) - $t0) * 1000);

            $status = $body === false ? 'err' : ($http >= 200 && $http < 300 ? 'ok' : 'warn');
            return ['ok' => true, 'check' => [
                'name'   => 'Наш эндпоинт за реле',
                'status' => $status,
                'detail' => $body === false ? "Не ответил: $err" : "HTTP $http за $ms мс · " . mb_substr(strip_tags((string)$body), 0, 120),
                'hint'   => $status === 'ok' ? '' : 'Telegram увидит ровно этот ответ. Всё, кроме 2xx, он считает сбоем и будет повторять доставку.',
            ]];
        }

        // --- достанет ли Telegram картинку по ссылке
        case 'filecheck': {
            $dir = __DIR__ . '/user_screens';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $name  = 'probe_' . bin2hex(random_bytes(6)) . '.png';
            $probe = $dir . '/' . $name;
            // 1×1 PNG: проверяем доступность каталога снаружи, а не картинку саму по себе
            if (@file_put_contents($probe, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')) === false) {
                return ['ok' => true, 'check' => ['name' => 'Файлы доступны снаружи', 'status' => 'err',
                    'detail' => 'не удалось записать пробный файл в user_screens',
                    'hint'   => 'Дайте каталогу права на запись — иначе картинки календарей не сохранятся вовсе.']];
            }

            $url = public_url('user_screens/' . $name);
            $t0  = microtime(true);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
            $body = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $err  = curl_error($ch);
            curl_close($ch);
            @unlink($probe);

            $ms       = (int)round((microtime(true) - $t0) * 1000);
            $png      = is_string($body) && strncmp($body, "\x89PNG", 4) === 0;
            $viaRelay = Settings::get('pub_base', '') !== '';

            if ($png && $http === 200) {
                $status = 'ok';
                $detail = "$url — 200, $type, $ms мс" . ($viaRelay ? ', через реле' : ', напрямую с сервера');
                $hint   = $viaRelay ? '' : 'Ссылка ведёт на сам сервер. Если Telegram до него не достаёт, отправка каждый раз будет уходить в загрузку файлом — заполните «Публичный адрес файлов».';
            } else {
                $status = 'err';
                $detail = $body === false ? "$url — не ответил: $err"
                        : "$url — HTTP $http, " . ($type ?: 'без типа') . ', ' . strlen((string)$body) . ' байт';
                $hint   = 'Telegram скачивает картинку ровно по этой ссылке. Проверьте, что реле проксирует каталог user_screens, а не только вебхук.';
            }
            Log::info('admin', 'Проверка доступности файлов', ['url' => $url, 'http' => $http, 'успех' => $status === 'ok']);
            return ['ok' => true, 'check' => ['name' => 'Файлы доступны снаружи', 'status' => $status, 'detail' => $detail, 'hint' => $hint]];
        }

        // --- сквозная проверка: та же цепочка, которой картинка уходит пользователю
        case 'testphoto': {
            $uid = (int)($_SESSION['admin']['uid'] ?? 0) ?: (int)($GLOBALS['admin_ids'][0] ?? 0);
            if (!$uid) return ['ok' => false, 'message' => 'Некому слать: войдите через Telegram или добавьте свой ID в «Настройки → Доступ»'];

            $dir = __DIR__ . '/user_screens';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $name = 'test_' . bin2hex(random_bytes(6)) . '.png';
            $path = $dir . '/' . $name;

            if (function_exists('imagecreatetruecolor')) {
                $im = imagecreatetruecolor(320, 160);
                imagefilledrectangle($im, 0, 0, 320, 160, imagecolorallocate($im, 29, 209, 161));
                imagestring($im, 5, 20, 70, 'Years in pixels: test', imagecolorallocate($im, 255, 255, 255));
                imagepng($im, $path);
                imagedestroy($im);
            } else {
                @file_put_contents($path, base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
            }

            $r = (new TelegramBot())->sendPhotoBestEffort($uid, $path, public_url('user_screens/' . $name),
                'Тестовая картинка из панели');
            @unlink($path);

            $said = ['url' => 'Доставлено ссылкой — Telegram скачал файл сам',
                     'upload' => 'Доставлено загрузкой файла',
                     'link'   => 'Картинка не прошла, отправлена ссылка текстом'];
            return ['ok' => true, 'check' => [
                'name'   => 'Отправка картинки себе',
                'status' => $r['ok'] ? ($r['via'] === 'url' ? 'ok' : 'warn') : 'err',
                'detail' => ($r['ok'] ? ($said[$r['via']] ?? $r['via']) : 'Не доставлено')
                          . ($r['tries'] ? ' · не сработало: ' . implode(' | ', $r['tries']) : ''),
                'hint'   => $r['via'] === 'upload'
                    ? 'Работает, но каждый раз тащит файл через ваш канал наружу. Ссылка дешевле — почините отдачу user_screens через реле.'
                    : ($r['via'] === 'link' ? 'Ни ссылка, ни загрузка не прошли — причина каждой попытки во вкладке «Журнал».' : ''),
            ]];
        }

        // --- окружение и права
        case 'env': {
            $checks = [];
            $checks[] = ['name' => 'Версия PHP', 'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'warn',
                'detail' => PHP_VERSION, 'hint' => version_compare(PHP_VERSION, '7.4', '>=') ? '' : 'Код рассчитан на 7.4 и выше'];

            $curl = curl_version();
            $checks[] = ['name' => 'cURL', 'status' => 'ok', 'detail' => $curl['version'] . ', SSL: ' . $curl['ssl_version'], 'hint' => ''];
            $checks[] = ['name' => 'Поддержка DNS-over-HTTPS', 'status' => defined('CURLOPT_DOH_URL') ? 'ok' : 'warn',
                'detail' => defined('CURLOPT_DOH_URL') ? 'есть' : 'нет, настройка DoH будет проигнорирована',
                'hint'   => defined('CURLOPT_DOH_URL') ? '' : 'Нужен cURL 7.62+. Обходной путь — канал через реле Cloudflare.'];
            $checks[] = ['name' => 'Короткие теги PHP', 'status' => ini_get('short_open_tag') ? 'warn' : 'ok',
                'detail' => ini_get('short_open_tag') ? 'включены' : 'выключены',
                'hint'   => ini_get('short_open_tag') ? 'Не опасно, но код должен открываться через <?php в любом случае.' : ''];

            $oc = function_exists('opcache_get_configuration') ? (opcache_get_configuration()['directives'] ?? []) : [];
            if (!empty($oc['opcache.enable'])) {
                $stale = isset($oc['opcache.validate_timestamps']) && !$oc['opcache.validate_timestamps'];
                $checks[] = ['name' => 'opcache', 'status' => $stale ? 'warn' : 'ok',
                    'detail' => $stale ? 'включён, проверка времени файлов выключена' : 'включён, файлы перечитываются',
                    'hint'   => $stale ? 'Правки config.php (пароль, админы, токен) не подхватятся, пока не перезапустят PHP. Панель сбрасывает кэш сама, но при этой настройке сброс может не сработать.' : ''];
            }

            $canCfg = is_file(config_path()) ? is_writable(config_path()) : is_writable(__DIR__);
            $checks[] = ['name' => 'config.php правится из панели', 'status' => $canCfg ? 'ok' : 'warn',
                'detail' => $canCfg ? 'да' : 'нет, файл только на чтение',
                'hint'   => $canCfg ? '' : 'Смена пароля и списка админов покажет готовый текст файла для ручной замены.'];

            foreach ([Log::dir() => 'Каталог журнала', __DIR__ . '/user_screens' => 'Каталог картинок'] as $dir => $name) {
                $checks[] = ['name' => $name, 'status' => is_dir($dir) && is_writable($dir) ? 'ok' : 'err',
                    'detail' => is_dir($dir) ? ($dir . (is_writable($dir) ? ' — доступен на запись' : ' — только чтение')) : 'не существует',
                    'hint'   => is_dir($dir) && is_writable($dir) ? '' : 'Создайте каталог и дайте права на запись, иначе журнал и картинки молча теряются.'];
            }

            try {
                $tables = [];
                foreach (db()->query('SHOW TABLES') as $r) $tables[] = reset($r);
                $need = array_keys(setup_schema());
                $miss = array_diff($need, $tables);
                $cnt  = (int)db()->query('SELECT COUNT(*) FROM calendar_entries')->fetchColumn();
                $checks[] = ['name' => 'База данных', 'status' => $miss ? 'err' : 'ok',
                    'detail' => $miss ? 'нет таблиц: ' . implode(', ', $miss) : count($tables) . ' таблиц, записей в календаре: ' . $cnt,
                    'hint'   => $miss ? 'Нажмите «Обновить схему» ниже.' : ''];
            } catch (Throwable $e) {
                $checks[] = ['name' => 'База данных', 'status' => 'err', 'detail' => $e->getMessage(), 'hint' => 'Проверьте доступы в config.php'];
            }

            // Журнал не должен читаться из браузера: на nginx .htaccess не работает вовсе.
            $base = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                  . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
            $probe = $base . '/logs/' . date('Y-m-d') . '.jsonl';
            $ch = curl_init($probe);
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $checks[] = ['name' => 'Журнал закрыт снаружи',
                'status' => $code === 200 ? 'err' : ($code === 0 ? 'warn' : 'ok'),
                'detail' => $code === 200 ? "$probe отдаётся браузеру (HTTP 200)"
                          : ($code === 0 ? "сервер не ответил сам себе, проверьте вручную: $probe" : "HTTP $code на $probe"),
                'hint'   => $code === 200 ? 'В журнале видны user_id и внутренние адреса. Закройте каталог правилом веб-сервера — на nginx .htaccess не действует.' : ''];

            return ['ok' => true, 'checks' => $checks];
        }

        // --- схема БД
        case 'setup': {
            $r = setup_run();
            $map = ['ok' => 'ok', 'added' => 'ok', 'warn' => 'warn', 'err' => 'err'];
            return ['ok' => true, 'checks' => array_map(fn($s) => [
                'name'   => $s['what'],
                'status' => $map[$s['status']] ?? 'warn',
                'detail' => ($s['status'] === 'added' ? '✚ ' : '') . $s['detail'],
                'hint'   => '',
            ], $r['steps'])];
        }

        // --- журнал
        case 'logs': {
            $recs = Log::tail(
                (string)($in['date'] ?? date('Y-m-d')),
                min(1000, max(20, (int)($in['limit'] ?? 200))),
                ['lvl' => (string)($in['lvl'] ?? ''), 'cat' => (string)($in['cat'] ?? ''), 'q' => (string)($in['q'] ?? '')]
            );
            $days = array_map(fn($f) => basename($f, '.jsonl'), glob(Log::dir() . '/*.jsonl') ?: []);
            rsort($days);
            return ['ok' => true, 'records' => $recs, 'days' => $days];
        }

        // --- пользователи
        case 'users': {
            $q = trim((string)($in['q'] ?? ''));
            $sql = 'SELECT user_id, user_firstname, user_lastname, user_nickname, user_timezone,
                           user_entriestotal, user_lastentry, user_private, user_registerdate
                    FROM calendar_users';
            $args = [];
            if ($q !== '') {
                $sql .= ' WHERE user_id LIKE ? OR user_nickname LIKE ? OR user_firstname LIKE ? OR user_lastname LIKE ?';
                $args = array_fill(0, 4, "%$q%");
            }
            $sql .= ' ORDER BY user_lastentry IS NULL, user_lastentry DESC LIMIT 200';
            $st = db()->prepare($sql);
            $st->execute($args);
            return ['ok' => true, 'users' => $st->fetchAll()];
        }

        // --- календарь одного пользователя
        case 'calendar': {
            $uid  = (int)($in['uid'] ?? 0);
            $year = (int)($in['year'] ?? date('Y'));
            if (!$uid) return ['ok' => false, 'message' => 'Не выбран пользователь'];
            $st = db()->prepare("SELECT entry_date, mood_key, description, alcohol, sport, sex, friends, romantic, crying, WomanDay
                                 FROM calendar_entries WHERE user_id = ? AND YEAR(entry_date) = ? ORDER BY entry_date");
            $st->execute([$uid, $year]);
            $days = [];
            foreach ($st as $r) $days[$r['entry_date']] = $r;

            $ys = db()->prepare('SELECT DISTINCT YEAR(entry_date) y FROM calendar_entries WHERE user_id = ? ORDER BY y DESC');
            $ys->execute([$uid]);
            Log::info('admin', 'Просмотр календаря пользователя', ['user_id' => $uid, 'год' => $year]);
            return ['ok' => true, 'days' => $days, 'years' => array_column($ys->fetchAll(), 'y')];
        }
    }
    return ['ok' => false, 'message' => 'Неизвестное действие'];
}

/** Что делать с конкретной причиной отказа — подсказка рядом с проверкой, а не в документации. */
function tg_hint(string $reason): string
{
    return [
        'not_configured' => 'Канал не настроен — заполните его поля на вкладке «Настройки» или уберите из порядка.',
        'no_token'       => 'Впишите $bot_token в config.php.',
        'dns'            => 'Включите DNS-over-HTTPS или переключитесь на реле Cloudflare: имя api.telegram.org не разрешается.',
        'connect'        => 'Адрес доступен, но соединение отклоняется. Для прокси — проверьте хост и порт.',
        'timeout'        => 'Ответа нет вовсе — типично для блокировки по IP. Реле Cloudflare обходит это.',
        'reset'          => 'Соединение рвут на середине — почти наверняка фильтрация на пути. Идите через реле.',
        'tls'            => 'TLS не устанавливается: блокировка по SNI либо подмена сертификата.',
        'bad_json'       => 'Отвечает не Telegram, а что-то по пути. Проверьте, что реле проксирует, а не отдаёт свою страницу.',
        'api_error'      => 'Канал работает, отказ пришёл от самого Telegram — смотрите текст ошибки.',
    ][$reason] ?? '';
}

// ------------------------------------------------------------- отрисовка

$me   = admin_user();
$csrf = admin_csrf();
$spec = settings_spec();
$vals = Settings::all() + settings_defaults();
$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Панель · Years in pixels</title>
<script src="../js/telegram-web-app.js"></script>
<style>
:root{
  --bg:#0f1419; --panel:#181f28; --panel-2:#1f2833; --line:#2b3644;
  --text:#e4e9ef; --muted:#8b98a9; --accent:#4a9eff; --accent-dim:#2d6fb8;
  --ok:#3fb950; --err:#f85149; --warn:#d29922;
  --radius:8px; --mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);
     font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
a{color:var(--accent)}
.wrap{max-width:1000px;margin:0 auto;padding:16px}
.nav{display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--line);margin-bottom:16px}
.nav__tabs{display:flex;overflow-x:auto;scrollbar-width:none;min-width:0;flex:1}
.nav__tabs::-webkit-scrollbar{display:none}
.nav__tab{white-space:nowrap;padding:10px 13px;font-size:14px;color:var(--muted);
          background:none;border:0;border-bottom:2px solid transparent;cursor:pointer}
.nav__tab--on{color:var(--text);border-bottom-color:var(--accent)}
.nav__who{font-size:12.5px;color:var(--muted);white-space:nowrap}
h1{font-size:20px;font-weight:600;margin:0 0 14px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px;margin-bottom:16px}
.card__title{font-size:15px;font-weight:600;margin:0 0 4px}
.card__hint{font-size:13px;color:var(--muted);margin:0 0 14px}
.field{margin-bottom:13px}
.field__label{display:block;font-size:13.5px;margin-bottom:4px}
.field__hint{font-size:13px;color:var(--muted);margin-top:4px}
input[type=text],input[type=password],input[type=number],input[type=search],select{
  width:100%;padding:8px 10px;background:var(--panel-2);color:var(--text);
  border:1px solid var(--line);border-radius:6px;font:inherit;font-size:14px}
input:focus,select:focus{outline:none;border-color:var(--accent)}
.btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.btn{padding:8px 14px;font-size:14px;border-radius:6px;border:1px solid var(--line);
     background:var(--panel-2);color:var(--text);cursor:pointer}
.btn:hover{border-color:var(--accent)}
.btn--main{background:var(--accent-dim);border-color:var(--accent-dim)}
.btn--danger{color:var(--err)}
.btn--sm{padding:4px 9px;font-size:12.5px}
.check{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid var(--line);font-size:13.5px}
.check:last-child{border-bottom:0}
.check__mark{flex:0 0 16px;font-weight:700}
.check--ok .check__mark{color:var(--ok)} .check--err .check__mark{color:var(--err)}
.check--warn .check__mark{color:var(--warn)} .check--wait .check__mark{color:var(--muted)}
.check__name{flex:0 0 190px}
.check__body{flex:1;min-width:0}
.check__detail{font-family:var(--mono);font-size:12.5px;word-break:break-word}
.check__hint{color:var(--muted);font-size:12.5px;margin-top:2px}
.msg{padding:9px 12px;border-radius:6px;border-left:3px solid;font-size:13.5px;margin-bottom:10px}
.msg--ok{background:rgba(63,185,80,.09);border-left-color:var(--ok)}
.msg--err{background:rgba(248,81,73,.09);border-left-color:var(--err)}
.msg--warn{background:rgba(210,153,34,.09);border-left-color:var(--warn)}
.pill{display:inline-block;padding:1px 9px;border-radius:100px;font-size:12px;background:var(--panel-2);color:var(--muted)}
.row{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--line)}
.row:last-child{border-bottom:0}
.grow{flex:1;min-width:0}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th,td{text-align:left;padding:7px 8px;border-bottom:1px solid var(--line)}
th{color:var(--muted);font-weight:500;font-size:12.5px}
tbody tr{cursor:pointer}
tbody tr:hover{background:var(--panel-2)}
.mono{font-family:var(--mono);font-size:12.5px}
.log{display:grid;grid-template-columns:auto auto auto 1fr;gap:6px 10px;align-items:start;font-size:13px}
.log__t,.log__cat{font-family:var(--mono);font-size:11.5px;color:var(--muted);white-space:nowrap}
.log__lvl{font-family:var(--mono);font-size:11.5px;font-weight:700}
.log__lvl--debug{color:var(--muted)} .log__lvl--info{color:var(--accent)}
.log__lvl--warn{color:var(--warn)}   .log__lvl--error{color:var(--err)}
.log__msg{word-break:break-word}
.log__ctx{grid-column:1/-1;margin:0 0 6px;padding:7px 9px;background:var(--panel-2);border-radius:6px;
          font-family:var(--mono);font-size:12px;white-space:pre-wrap;color:var(--muted)}
.cal{display:grid;grid-template-columns:34px repeat(31,1fr);gap:2px;min-width:600px}
.cal__cell{aspect-ratio:1;border-radius:2px;background:var(--panel-2)}
.cal__cell--empty{background:transparent}
.cal__m{font-size:11.5px;color:var(--muted);align-self:center}
.scroll{overflow-x:auto}
.busy{position:fixed;inset:0;background:rgba(15,20,25,.6);backdrop-filter:blur(2px);
      display:none;align-items:center;justify-content:center;flex-direction:column;gap:12px;z-index:50}
.busy--on{display:flex}
.busy__txt{font-size:13.5px;color:var(--muted);text-align:center;max-width:320px}
.spin{width:26px;height:26px;border:3px solid var(--line);border-top-color:var(--accent);
      border-radius:50%;animation:sp .8s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.toasts{position:fixed;right:14px;bottom:14px;display:flex;flex-direction:column;gap:8px;z-index:60}
.toast{padding:9px 13px;border-radius:var(--radius);background:var(--panel);border:1px solid var(--line);
       border-left:3px solid var(--accent);font-size:13.5px;max-width:340px}
.toast--ok{border-left-color:var(--ok)} .toast--err{border-left-color:var(--err)}
.hidden{display:none}
</style>
</head>
<body>
<div class="busy" id="busy"><div class="spin"></div><div class="busy__txt" id="busyTxt"></div></div>
<div class="toasts" id="toasts"></div>

<?php if ($installMode):
    Log::warn('admin', 'Открыт установщик', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?', 'режим' => install_mode()]);
    $c  = config_current();
    $up = install_mode() === 'upgrade';   // конфиг с доступами уже есть — не хватает только входа в панель
?>
<div class="wrap" style="max-width:620px">
  <h1><?= $up ? 'Обновление' : 'Установка' ?></h1>
  <?php if ($up): ?>
    <div class="msg msg--ok">Конфиг найден, доступы к базе и токен на месте — заново вводить их не нужно.
      Осталось задать вход в панель. Таблицы и данные не трогаются: установщик умеет только добавлять
      недостающие таблицы и столбцы, удалять он не умеет вовсе.</div>
  <?php endif; ?>
  <div class="msg msg--warn">Пока пароль панели не задан, эта страница открыта любому, кто знает адрес.
    Задайте пароль сразу.</div>

  <?php /* Секреты в разметку не выводим: до задания пароля эта страница доступна без входа. */ ?>
  <details class="card" <?= $up ? '' : 'open' ?>>
    <summary class="card__title" style="cursor:pointer">База данных<?= $up ? ' — уже настроена' : '' ?></summary>
    <p class="card__hint">Проверяется не только вход, но и право создавать таблицы — иначе установка «пройдёт», а всё встанет на первой же таблице.</p>
    <div class="field"><label class="field__label" for="i_db_host">Хост</label>
      <input id="i_db_host" data-i="db_host" type="text" value="<?= $h($c['db_host']) ?>"></div>
    <div class="field"><label class="field__label" for="i_db_name">База</label>
      <input id="i_db_name" data-i="db_name" type="text" value="<?= $h($c['db_name']) ?>"></div>
    <div class="field"><label class="field__label" for="i_db_user">Пользователь</label>
      <input id="i_db_user" data-i="db_user" type="text" value="<?= $h($c['db_user']) ?>"></div>
    <div class="field"><label class="field__label" for="i_db_pass">Пароль</label>
      <input id="i_db_pass" data-i="db_pass" type="password" autocomplete="new-password"
             placeholder="<?= $c['db_pass'] !== '' ? 'задан — пустое поле оставит прежний' : '' ?>"></div>
    <div id="dbChecks"></div>
    <div class="btns"><button class="btn" id="dbTest">Проверить подключение</button></div>
  </details>

  <details class="card" <?= $up ? '' : 'open' ?>>
    <summary class="card__title" style="cursor:pointer">Telegram<?= $up && $c['bot_token'] !== '' ? ' — токен уже задан' : '' ?></summary>
    <p class="card__hint">Токен даёт @BotFather. Если Telegram у вас блокируется — впишите адрес реле Cloudflare, оно станет первым каналом связи.</p>
    <div class="field"><label class="field__label" for="i_bot_token">Токен бота</label>
      <input id="i_bot_token" data-i="bot_token" type="text" autocomplete="off"
             placeholder="<?= $c['bot_token'] !== '' ? 'задан — пустое поле оставит прежний' : '000000000:AA...' ?>"></div>
    <div class="field"><label class="field__label" for="i_relay">Адрес реле Cloudflare</label>
      <input id="i_relay" data-i="relay" type="text" placeholder="https://tg.example.workers.dev">
      <div class="field__hint">Без завершающего слэша. Можно оставить пустым и заполнить позже в настройках.</div></div>
    <div id="botChecks"></div>
    <div class="btns"><button class="btn" id="botTest">Проверить бота</button></div>
  </details>

  <div class="card">
    <div class="card__title">Доступ в панель</div>
    <p class="card__hint">Хватит любого из двух способов, но лучше настроить оба: пароль работает с компьютера, Telegram ID — при открытии панели из бота.</p>
    <div class="field"><label class="field__label" for="i_password">Пароль панели</label>
      <input id="i_password" data-i="password" type="password" autocomplete="new-password">
      <div class="field__hint">От 8 символов. Хэш посчитается сам, руками ничего вписывать не нужно.</div></div>
    <div class="field"><label class="field__label" for="i_password2">Пароль ещё раз</label>
      <input id="i_password2" data-i="password2" type="password" autocomplete="new-password"></div>
    <div class="field"><label class="field__label" for="i_admin_ids">Telegram ID администраторов</label>
      <input id="i_admin_ids" data-i="admin_ids" type="text" value="<?= $h(implode(', ', $c['admin_ids'])) ?>" placeholder="123456789, 987654321">
      <div class="btns"><button class="btn btn--sm" id="myId">Подставить мой ID из Telegram</button></div></div>
    <div class="field"><label class="field__label" for="i_origins">Откуда фронтенду разрешено дёргать API</label>
      <input id="i_origins" data-i="origins" type="text" value="<?= $h(implode(', ', $c['allowed_origins'])) ?>">
      <div class="field__hint">Через запятую. Обычно https://web.telegram.org и адрес вашего сайта.</div></div>
  </div>

  <div class="card">
    <div id="instChecks"></div>
    <div class="btns"><button class="btn btn--main" id="instSave"><?= $up ? 'Сохранить и обновить схему' : 'Установить' ?></button></div>
    <div id="manual"></div>
  </div>
</div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const MARKS = {ok:'✓', err:'✗', warn:'!', wait:'…'};
function renderChecks(box, items) {
  box.innerHTML = '';
  for (const it of items) {
    const d = document.createElement('div'); d.className = 'check check--' + it.status;
    const m = document.createElement('div'); m.className = 'check__mark'; m.textContent = MARKS[it.status] || '·';
    const n = document.createElement('div'); n.className = 'check__name'; n.textContent = it.name;
    const b = document.createElement('div'); b.className = 'check__body';
    const t = document.createElement('div'); t.className = 'check__detail'; t.textContent = it.detail || ''; b.appendChild(t);
    if (it.hint) { const hh = document.createElement('div'); hh.className = 'check__hint'; hh.textContent = it.hint; b.appendChild(hh); }
    d.append(m, n, b); box.appendChild(d);
  }
}
const vals = () => Object.fromEntries([...document.querySelectorAll('[data-i]')].map(e => [e.dataset.i, e.value]));
async function api(a, body) {
  const r = await fetch('?a=' + a, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':CSRF}, body:JSON.stringify(body)});
  return r.json();
}
document.getElementById('dbTest').onclick = async () => {
  const box = document.getElementById('dbChecks');
  renderChecks(box, [{name:'Подключение к базе', status:'wait', detail:'Проверяем…'}]);
  const j = await api('inst_db', vals());
  renderChecks(box, j.check ? [j.check] : [{name:'Подключение к базе', status:'err', detail:j.message || 'не вышло'}]);
};
document.getElementById('botTest').onclick = async () => {
  const box = document.getElementById('botChecks');
  renderChecks(box, [{name:'Telegram', status:'wait', detail:'Проверяем оба пути, до 20 секунд…'}]);
  const j = await api('inst_bot', vals());
  renderChecks(box, j.checks || [{name:'Telegram', status:'err', detail:j.message || 'не вышло'}]);
};
document.getElementById('myId').onclick = e => {
  e.preventDefault();
  const id = window.Telegram?.WebApp?.initDataUnsafe?.user?.id;
  const f = document.getElementById('i_admin_ids');
  if (!id) { f.placeholder = 'страница открыта не из Telegram — впишите ID вручную'; return; }
  f.value = f.value ? f.value + ', ' + id : String(id);
};
document.getElementById('instSave').onclick = async () => {
  const box = document.getElementById('instChecks');
  renderChecks(box, [{name:'Установка', status:'wait', detail:'Пишем конфиг и создаём таблицы…'}]);
  const j = await api('inst_save', vals());
  if (!j.ok) {
    renderChecks(box, [{name:'Установка', status:'err', detail:j.message || 'не вышло'}]);
    if (j.manual) {
      const m = document.getElementById('manual'); m.innerHTML = '';
      const p = document.createElement('p'); p.className = 'card__hint';
      p.textContent = 'Создайте файл api/config.php с таким содержимым и обновите страницу:';
      const pre = document.createElement('pre'); pre.className = 'log__ctx'; pre.textContent = j.manual;
      m.append(p, pre);
    }
    return;
  }
  renderChecks(box, j.checks || []);
  const b = document.createElement('div'); b.className = 'btns';
  const go = document.createElement('button'); go.className = 'btn btn--main'; go.textContent = 'Открыть панель';
  go.onclick = () => location.href = 'admin.php';
  b.appendChild(go); box.appendChild(b);
};
</script>
</body></html>
<?php exit; endif; ?>

<?php if (!$me): ?>
<div class="wrap" style="max-width:420px;padding-top:60px">
  <h1>Панель Years in pixels</h1>
  <div class="card">
    <div class="card__title">Вход</div>
    <p class="card__hint">Из Telegram — одной кнопкой, если ваш аккаунт в списке администраторов. С компьютера — паролем из config.php.</p>
    <div id="loginMsg"></div>
    <div class="btns"><button class="btn btn--main" id="tgLogin">Войти через Telegram</button></div>
    <div class="field" style="margin-top:16px">
      <label class="field__label" for="pw">Пароль</label>
      <input type="password" id="pw" autocomplete="current-password">
    </div>
    <div class="btns"><button class="btn" id="pwLogin">Войти паролем</button></div>
  </div>
</div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const msg = (t, k) => { const d = document.getElementById('loginMsg');
  d.innerHTML = ''; const e = document.createElement('div'); e.className = 'msg msg--' + k; e.textContent = t; d.appendChild(e); };
async function login(body){
  const r = await fetch('?a=login', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':CSRF}, body:JSON.stringify(body)});
  const j = await r.json();
  if (j.ok) location.reload(); else msg(j.message || 'Не вышло', 'err');
}
document.getElementById('pwLogin').onclick = () => login({mode:'pass', password:document.getElementById('pw').value});
document.getElementById('pw').addEventListener('keydown', e => { if (e.key === 'Enter') document.getElementById('pwLogin').click(); });
document.getElementById('tgLogin').onclick = () => {
  const d = window.Telegram?.WebApp?.initData;
  if (!d) return msg('Страница открыта не из Telegram — данных подписи нет. Войдите паролем.', 'warn');
  login({mode:'tg', initData:d});
};
</script>
</body></html>
<?php exit; endif; ?>

<div class="wrap">
  <div class="nav">
    <div class="nav__tabs" id="tabs">
      <button class="nav__tab nav__tab--on" data-tab="diag">Диагностика</button>
      <button class="nav__tab" data-tab="settings">Настройки</button>
      <button class="nav__tab" data-tab="logs">Журнал</button>
      <button class="nav__tab" data-tab="users">Пользователи</button>
    </div>
    <span class="nav__who"><?= $h($me['name']) ?> · <?= $h($me['via']) ?></span>
    <a class="btn btn--sm" href="?a=logout">Выйти</a>
  </div>

  <!-- ------------------------------------------------ диагностика -->
  <section id="tab-diag">
    <h1>Диагностика</h1>

    <div class="card">
      <div class="card__title">Каналы до Bot API</div>
      <p class="card__hint">Каждая кнопка вызывает getMe ровно тем способом, которым ходит бот. Проверка идёт до 20 секунд — столько же, сколько боевой запрос.</p>
      <div id="chChecks"></div>
      <div class="btns">
        <?php foreach (tg_channels() as $k => $name): ?>
          <button class="btn" data-test="<?= $h($k) ?>">Проверить: <?= $h($name) ?></button>
        <?php endforeach; ?>
        <button class="btn btn--main" id="testAll">Проверить все</button>
      </div>
    </div>

    <div class="card">
      <div class="card__title">Входящие: вебхук</div>
      <p class="card__hint">Telegram сам проверяет адрес при установке — это может занять до двух минут.</p>
      <div id="whChecks"></div>
      <div class="btns">
        <button class="btn" data-wh="info">Что стоит сейчас</button>
        <button class="btn" id="inbound">Проверить наш эндпоинт</button>
        <button class="btn btn--main" data-wh="set">Установить из настроек</button>
        <button class="btn btn--danger" data-wh="del">Снять вебхук</button>
      </div>
    </div>

    <div class="card">
      <div class="card__title">Входящие: картинки календарей</div>
      <p class="card__hint">Картинка уходит пользователю ссылкой — Telegram скачивает файл сам через инбаунд-реле.
        Не вышло — тот же файл отправляется загрузкой, и только если и это не прошло, человек получает ссылку текстом.
        Первая проверка кладёт в user_screens пробный файл и пробует забрать его снаружи, вторая гоняет всю цепочку целиком и шлёт картинку вам в Telegram.</p>
      <div id="fileChecks"></div>
      <div class="btns">
        <button class="btn btn--main" id="fileCheck">Проверить доступность файлов</button>
        <button class="btn" id="testPhoto">Отправить тестовую картинку себе</button>
      </div>
    </div>

    <div class="card">
      <div class="card__title">Окружение и база</div>
      <p class="card__hint">Права, версии, схема. «Обновить схему» создаёт недостающие таблицы и столбцы, ничего не удаляя.</p>
      <div id="envChecks"></div>
      <div class="btns">
        <button class="btn btn--main" id="envBtn">Проверить окружение</button>
        <button class="btn" id="setupBtn">Обновить схему</button>
      </div>
    </div>
  </section>

  <!-- --------------------------------------------------- настройки -->
  <section id="tab-settings" class="hidden">
    <h1>Настройки</h1>
    <?php foreach ($spec as $group => $fields): ?>
      <div class="card">
        <div class="card__title"><?= $h($group) ?></div>
        <?php foreach ($fields as $k => [$label, $type, $def, $hint]):
              $v = (string)($vals[$k] ?? $def); ?>
          <div class="field">
            <?php if ($type === 'order'):
                    $order = array_values(array_filter(array_map('trim', explode(',', $v))));
                    $rest  = array_diff(array_keys(tg_channels()), $order); ?>
              <label class="field__label"><?= $h($label) ?></label>
              <div id="orderBox" data-key="<?= $h($k) ?>">
                <?php foreach (array_merge($order, array_values($rest)) as $ch): ?>
                  <div class="row" data-ch="<?= $h($ch) ?>">
                    <input type="checkbox" <?= in_array($ch, $order, true) ? 'checked' : '' ?>>
                    <span class="grow"><?= $h(tg_channels()[$ch] ?? $ch) ?> <span class="pill"><?= $h($ch) ?></span></span>
                    <button class="btn btn--sm" data-move="-1">↑</button>
                    <button class="btn btn--sm" data-move="1">↓</button>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php elseif ($type === 'check'): ?>
              <label class="field__label">
                <input type="checkbox" data-set="<?= $h($k) ?>" data-check="1" <?= $v === '1' ? 'checked' : '' ?>
                       style="width:auto;margin-right:6px"><?= $h($label) ?>
              </label>
            <?php elseif (strpos($type, 'select:') === 0): ?>
              <label class="field__label" for="f_<?= $h($k) ?>"><?= $h($label) ?></label>
              <select id="f_<?= $h($k) ?>" data-set="<?= $h($k) ?>">
                <?php foreach (explode(',', substr($type, 7)) as $opt): ?>
                  <option value="<?= $h($opt) ?>" <?= $opt === $v ? 'selected' : '' ?>><?= $h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($type === 'password'): ?>
              <?php /* Значение пароля в разметку не выводим: незачем держать его в исходнике страницы. */ ?>
              <label class="field__label" for="f_<?= $h($k) ?>"><?= $h($label) ?></label>
              <input id="f_<?= $h($k) ?>" data-set="<?= $h($k) ?>" data-pw="1" type="password" autocomplete="new-password"
                     placeholder="<?= $v === '' ? 'не задан' : 'задан — пустое поле оставит прежний' ?>">
            <?php else: ?>
              <label class="field__label" for="f_<?= $h($k) ?>"><?= $h($label) ?></label>
              <input id="f_<?= $h($k) ?>" data-set="<?= $h($k) ?>" autocomplete="off"
                     type="<?= $type === 'number' ? 'number' : 'text' ?>"
                     value="<?= $h($v) ?>">
            <?php endif; ?>
            <?php if ($hint): ?><div class="field__hint"><?= $h($hint) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <div class="card">
      <p class="card__hint">Настройки выше лежат в базе.</p>
      <div class="btns"><button class="btn btn--main" id="saveBtn">Сохранить</button></div>
    </div>

    <?php $c = config_current(); ?>
    <div class="card">
      <div class="card__title">Доступ</div>
      <p class="card__hint">Это правит config.php, а не базу: токен бота, список админов и пароль панели
        держатся отдельно, чтобы взлом базы не открывал вход сюда.</p>
      <div class="field"><label class="field__label" for="ac_pw">Новый пароль панели</label>
        <input id="ac_pw" data-ac="password" type="password" autocomplete="new-password" placeholder="<?= $c['admin_password_hash'] ? 'задан — пустое поле оставит прежний' : 'не задан' ?>">
        <div class="field__hint">От 8 символов.</div></div>
      <div class="field"><label class="field__label" for="ac_pw2">Ещё раз</label>
        <input id="ac_pw2" data-ac="password2" type="password" autocomplete="new-password"></div>
      <div class="field"><label class="field__label" for="ac_ids">Telegram ID администраторов</label>
        <input id="ac_ids" data-ac="admin_ids" type="text" value="<?= $h(implode(', ', $c['admin_ids'])) ?>"></div>
      <div class="field"><label class="field__label" for="ac_tok">Токен бота</label>
        <input id="ac_tok" data-ac="bot_token" type="text" value="<?= $h($c['bot_token']) ?>">
        <div class="field__hint">Меняется при отзыве токена в @BotFather. После смены проверьте каналы на вкладке «Диагностика».</div></div>
      <div class="field"><label class="field__label" for="ac_org">Разрешённые источники запросов</label>
        <input id="ac_org" data-ac="origins" type="text" value="<?= $h(implode(', ', $c['allowed_origins'])) ?>"></div>
      <div id="acManual"></div>
      <div class="btns"><button class="btn btn--main" id="acBtn">Сохранить доступ</button></div>
    </div>
  </section>

  <!-- ------------------------------------------------------ журнал -->
  <section id="tab-logs" class="hidden">
    <h1>Журнал</h1>
    <div class="card">
      <div class="row" style="gap:8px;flex-wrap:wrap;border:0">
        <select id="logDay" style="width:auto"></select>
        <select id="logLvl" style="width:auto">
          <option value="">все уровни</option><option value="info">от info</option>
          <option value="warn">от warn</option><option value="error">только error</option>
        </select>
        <select id="logCat" style="width:auto">
          <option value="">все категории</option>
          <option value="tg">tg</option><option value="webhook">webhook</option><option value="api">api</option>
          <option value="auth">auth</option><option value="db">db</option><option value="admin">admin</option>
        </select>
        <input type="search" id="logQ" placeholder="подстрока" style="width:auto;flex:1;min-width:120px">
        <button class="btn" id="logBtn">Показать</button>
      </div>
    </div>
    <div class="card"><div class="log" id="logOut"></div></div>
  </section>

  <!-- ------------------------------------------------ пользователи -->
  <section id="tab-users" class="hidden">
    <h1>Пользователи</h1>
    <div class="card">
      <div class="row" style="border:0">
        <input type="search" id="userQ" placeholder="id, ник или имя" class="grow">
        <button class="btn" id="userBtn">Найти</button>
      </div>
    </div>
    <div class="card scroll"><table id="userTable"><tbody></tbody></table></div>
    <div class="card hidden" id="calCard">
      <div class="card__title" id="calTitle"></div>
      <p class="card__hint">Только чтение. Наведите на клетку — покажет настроение, описание и отметки дня.</p>
      <div class="row" style="border:0"><select id="calYear" style="width:auto"></select></div>
      <div class="scroll"><div class="cal" id="calGrid"></div></div>
    </div>
  </section>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const MOODS = <?= json_encode([
    'great' => ['Классный день', '#1dd1a1'], 'good' => ['Хороший день', '#54a0ff'],
    'ok' => ['Обычный день', '#ffda79'],     'sad' => ['Грустный день', '#ffb142'],
    'angry' => ['Злой день', '#ff5252'],     'awful' => ['Отвратительный день', '#747d8c'],
    'tired' => ['Усталый день', '#9c88ff'],  'sick' => ['Болезнь', '#ff793f'],
], JSON_UNESCAPED_UNICODE) ?>;
const ACT = {alcohol:'🍷', sport:'💪', sex:'🍓', friends:'🤟', romantic:'💕', crying:'😭', WomanDay:'🩸'};
const MONTHS = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];

// --- три способа показать, что происходит: оверлей, уведомление, список проверок
const busyEl = document.getElementById('busy'), busyTxt = document.getElementById('busyTxt');
function busy(on, text) { busyTxt.textContent = text || ''; busyEl.classList.toggle('busy--on', !!on); }
function toast(text, kind) {
  const t = document.createElement('div');
  t.className = 'toast toast--' + (kind || 'ok'); t.textContent = text;
  document.getElementById('toasts').appendChild(t);
  setTimeout(() => t.remove(), 5000);
}
const MARKS = {ok:'✓', err:'✗', warn:'!', wait:'…'};
function renderChecks(box, items, append) {
  if (!append) box.innerHTML = '';
  for (const it of items) {
    const d = document.createElement('div');
    d.className = 'check check--' + it.status;
    d.dataset.name = it.name;
    const m = document.createElement('div'); m.className = 'check__mark'; m.textContent = MARKS[it.status] || '·';
    const n = document.createElement('div'); n.className = 'check__name'; n.textContent = it.name;
    const b = document.createElement('div'); b.className = 'check__body';
    const dt = document.createElement('div'); dt.className = 'check__detail'; dt.textContent = it.detail || '';
    b.appendChild(dt);
    if (it.hint) { const hn = document.createElement('div'); hn.className = 'check__hint'; hn.textContent = it.hint; b.appendChild(hn); }
    d.append(m, n, b);
    const old = box.querySelector('[data-name="' + CSS.escape(it.name) + '"]');
    if (old) old.replaceWith(d); else box.appendChild(d);
  }
}
async function api(a, body) {
  const r = await fetch('?a=' + a, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':CSRF},
                                    body: JSON.stringify(body || {})});
  if (r.status === 403) { toast('Сессия закончилась, обновите страницу', 'err'); return {ok:false}; }
  return r.json();
}

// --- вкладки
document.getElementById('tabs').addEventListener('click', e => {
  const b = e.target.closest('[data-tab]'); if (!b) return;
  document.querySelectorAll('.nav__tab').forEach(t => t.classList.toggle('nav__tab--on', t === b));
  document.querySelectorAll('section[id^=tab-]').forEach(s => s.classList.toggle('hidden', s.id !== 'tab-' + b.dataset.tab));
});

// --- проверки каналов
const chBox = document.getElementById('chChecks');
async function testChannel(ch, label) {
  renderChecks(chBox, [{name: label, status:'wait', detail:'Проверяем, до 20 секунд…', hint:''}], true);
  const j = await api('test', {channel: ch});
  if (j.check) renderChecks(chBox, [j.check], true);
  else toast(j.message || 'Проверка не выполнилась', 'err');
}
document.querySelectorAll('[data-test]').forEach(b =>
  b.onclick = () => testChannel(b.dataset.test, b.textContent.replace('Проверить: ', '')));
document.getElementById('testAll').onclick = async () => {
  for (const b of document.querySelectorAll('[data-test]')) await testChannel(b.dataset.test, b.textContent.replace('Проверить: ', ''));
};

// --- вебхук
const whBox = document.getElementById('whChecks');
function whInfo(info) {
  if (!info) return;
  const items = [
    {name:'Адрес в Telegram', status: info.url ? 'ok' : 'warn',
     detail: info.url || 'не установлен', hint: info.url ? '' : 'Заполните адрес в настройках и нажмите «Установить из настроек».'},
    {name:'Очередь обновлений', status: (info.pending_update_count > 50) ? 'warn' : 'ok',
     detail: String(info.pending_update_count ?? 0),
     hint: (info.pending_update_count > 50) ? 'Обновления копятся — значит, ваш эндпоинт отвечает не 2xx или не отвечает вовсе.' : ''},
    {name:'Секрет проверяется', status: info.has_custom_certificate === undefined && !info.url ? 'warn' : 'ok',
     detail: info.ip_address ? ('IP Telegram: ' + info.ip_address) : '—', hint:''},
  ];
  if (info.last_error_message) items.push({name:'Последняя ошибка доставки', status:'err',
    detail: (info.last_error_date ? new Date(info.last_error_date * 1000).toLocaleString() + ' · ' : '') + info.last_error_message,
    hint:'Так Telegram видит ваш эндпоинт. Пока тут ошибка, обновления не доходят.'});
  renderChecks(whBox, items);
}
document.querySelectorAll('[data-wh]').forEach(b => b.onclick = async () => {
  const op = b.dataset.wh;
  busy(true, op === 'set' ? 'Telegram сам проверяет ваш адрес, это может занять до двух минут…' : 'Спрашиваем Telegram…');
  const j = await api('webhook', {op});
  busy(false);
  if (!j.ok) return toast(j.message || 'Не вышло', 'err');
  if (j.message) toast(j.message);
  whInfo(j.info);
});
document.getElementById('inbound').onclick = async () => {
  renderChecks(whBox, [{name:'Наш эндпоинт за реле', status:'wait', detail:'Стучимся…', hint:''}], true);
  const j = await api('inbound', {});
  if (j.check) renderChecks(whBox, [j.check], true); else toast(j.message || 'Не вышло', 'err');
};

// --- картинки календарей
const fileBox = document.getElementById('fileChecks');
document.getElementById('fileCheck').onclick = async () => {
  renderChecks(fileBox, [{name:'Файлы доступны снаружи', status:'wait', detail:'Кладём пробный файл и пробуем забрать его снаружи…'}], true);
  const j = await api('filecheck', {});
  if (j.check) renderChecks(fileBox, [j.check], true); else toast(j.message || 'Не вышло', 'err');
};
document.getElementById('testPhoto').onclick = async () => {
  renderChecks(fileBox, [{name:'Отправка картинки себе', status:'wait', detail:'Пробуем ссылкой, потом загрузкой — до минуты…'}], true);
  const j = await api('testphoto', {});
  if (j.check) renderChecks(fileBox, [j.check], true); else toast(j.message || 'Не вышло', 'err');
};

// --- окружение и схема
document.getElementById('envBtn').onclick = async () => {
  busy(true, 'Проверяем окружение…');
  const j = await api('env', {}); busy(false);
  if (j.checks) renderChecks(document.getElementById('envChecks'), j.checks); else toast(j.message || 'Не вышло', 'err');
};
document.getElementById('setupBtn').onclick = async () => {
  busy(true, 'Приводим схему в порядок…');
  const j = await api('setup', {}); busy(false);
  if (j.checks) renderChecks(document.getElementById('envChecks'), j.checks); else toast(j.message || 'Не вышло', 'err');
};

// --- настройки
const orderBox = document.getElementById('orderBox');
orderBox?.addEventListener('click', e => {
  const b = e.target.closest('[data-move]'); if (!b) return;
  const row = b.closest('[data-ch]'), dir = +b.dataset.move;
  const sib = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
  if (sib) dir < 0 ? orderBox.insertBefore(row, sib) : orderBox.insertBefore(sib, row);
});
document.getElementById('saveBtn').onclick = async () => {
  const values = {};
  document.querySelectorAll('[data-set]').forEach(el => {
    if (el.dataset.pw && el.value === '') return;          // пустой пароль = «не менять»
    values[el.dataset.set] = el.dataset.check ? (el.checked ? '1' : '0') : el.value;
  });
  if (orderBox) {
    values[orderBox.dataset.key] = [...orderBox.querySelectorAll('[data-ch]')]
      .filter(r => r.querySelector('input').checked).map(r => r.dataset.ch).join(',');
  }
  const j = await api('save', {values});
  toast(j.message || (j.ok ? 'Сохранено' : 'Не сохранилось'), j.ok ? 'ok' : 'err');
};

// --- доступ: пароль, админы, токен
document.getElementById('acBtn').onclick = async () => {
  const body = Object.fromEntries([...document.querySelectorAll('[data-ac]')].map(e => [e.dataset.ac, e.value]));
  const j = await api('access', body);
  toast(j.message || (j.ok ? 'Сохранено' : 'Не сохранилось'), j.ok ? 'ok' : 'err');
  const m = document.getElementById('acManual'); m.innerHTML = '';
  if (j.manual) {
    const p = document.createElement('p'); p.className = 'card__hint';
    p.textContent = 'Файл не записался. Замените api/config.php этим содержимым:';
    const pre = document.createElement('pre'); pre.className = 'log__ctx'; pre.textContent = j.manual;
    m.append(p, pre);
  }
  if (j.ok) { document.getElementById('ac_pw').value = ''; document.getElementById('ac_pw2').value = ''; }
};

// --- журнал
async function loadLogs() {
  const j = await api('logs', {
    date: document.getElementById('logDay').value || new Date().toISOString().slice(0, 10),
    lvl:  document.getElementById('logLvl').value,
    cat:  document.getElementById('logCat').value,
    q:    document.getElementById('logQ').value,
  });
  const day = document.getElementById('logDay');
  if (j.days && day.options.length !== j.days.length) {
    const cur = day.value;
    day.innerHTML = '';
    j.days.forEach(d => { const o = document.createElement('option'); o.value = o.textContent = d; day.appendChild(o); });
    if (cur) day.value = cur;
  }
  const out = document.getElementById('logOut');
  out.innerHTML = '';
  if (!j.records || !j.records.length) { out.textContent = 'Записей нет'; return; }
  for (const r of j.records) {
    const t = document.createElement('div'); t.className = 'log__t'; t.textContent = (r.t || '').slice(11, 19);
    const l = document.createElement('div'); l.className = 'log__lvl log__lvl--' + r.lvl; l.textContent = r.lvl;
    const c = document.createElement('div'); c.className = 'log__cat'; c.textContent = r.cat;
    const m = document.createElement('div'); m.className = 'log__msg'; m.textContent = r.msg;
    out.append(t, l, c, m);
    if (r.ctx && Object.keys(r.ctx).length) {
      const x = document.createElement('div'); x.className = 'log__ctx';
      x.textContent = JSON.stringify(r.ctx, null, 1);
      out.appendChild(x);
    }
  }
}
document.getElementById('logBtn').onclick = loadLogs;
document.getElementById('logQ').addEventListener('keydown', e => { if (e.key === 'Enter') loadLogs(); });

// --- пользователи
let curUser = null;
async function loadUsers() {
  const j = await api('users', {q: document.getElementById('userQ').value});
  const tb = document.querySelector('#userTable tbody');
  tb.innerHTML = '';
  const head = document.createElement('tr');
  ['user_id','имя','ник','записей','последняя','пояс','приват'].forEach(t => {
    const th = document.createElement('th'); th.textContent = t; head.appendChild(th); });
  tb.appendChild(head);
  (j.users || []).forEach(u => {
    const tr = document.createElement('tr');
    [[u.user_id,'mono'], [((u.user_firstname||'') + ' ' + (u.user_lastname||'')).trim()],
     [u.user_nickname ? '@' + u.user_nickname : ''], [u.user_entriestotal],
     [u.user_lastentry || '—'], [u.user_timezone || '—'], [+u.user_private ? 'да' : 'нет']]
      .forEach(([v, cls]) => { const td = document.createElement('td'); if (cls) td.className = cls;
                               td.textContent = v ?? ''; tr.appendChild(td); });
    tr.onclick = () => openCalendar(u);
    tb.appendChild(tr);
  });
}
document.getElementById('userBtn').onclick = loadUsers;
document.getElementById('userQ').addEventListener('keydown', e => { if (e.key === 'Enter') loadUsers(); });

async function openCalendar(u, year) {
  curUser = u;
  const j = await api('calendar', {uid: u.user_id, year: year || new Date().getFullYear()});
  if (!j.ok) return toast(j.message || 'Не вышло', 'err');
  document.getElementById('calCard').classList.remove('hidden');
  document.getElementById('calTitle').textContent =
    ((u.user_firstname || '') + ' ' + (u.user_lastname || '')).trim() + ' · id ' + u.user_id;
  const ys = document.getElementById('calYear');
  const chosen = year || new Date().getFullYear();
  ys.innerHTML = '';
  (j.years && j.years.length ? j.years : [chosen]).forEach(y => {
    const o = document.createElement('option'); o.value = o.textContent = y;
    if (+y === +chosen) o.selected = true; ys.appendChild(o);
  });
  ys.onchange = () => openCalendar(curUser, ys.value);

  const g = document.getElementById('calGrid');
  g.innerHTML = '';
  for (let m = 0; m < 12; m++) {
    const lab = document.createElement('div'); lab.className = 'cal__m'; lab.textContent = MONTHS[m]; g.appendChild(lab);
    const dim = new Date(chosen, m + 1, 0).getDate();
    for (let d = 1; d <= 31; d++) {
      const cell = document.createElement('div');
      cell.className = 'cal__cell' + (d > dim ? ' cal__cell--empty' : '');
      if (d <= dim) {
        const key = chosen + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
        const e = j.days[key];
        if (e) {
          const mood = MOODS[e.mood_key];
          cell.style.background = mood ? mood[1] : '#555';
          const acts = Object.keys(ACT).filter(k => +e[k]).map(k => ACT[k]).join('');
          cell.title = key + ' · ' + (mood ? mood[0] : e.mood_key) + (acts ? ' ' + acts : '')
                     + (e.description ? '\n' + e.description : '');
        }
      }
      g.appendChild(cell);
    }
  }
}

loadLogs();
loadUsers();
</script>
</body>
</html>
