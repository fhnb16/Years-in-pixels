<?php
/**
 * Установщик схемы. Идемпотентен: гоняйте сколько угодно раз.
 * Создаёт отсутствующие таблицы, добавляет отсутствующие столбцы, досеивает настройки.
 * Ничего не удаляет и не меняет тип уже существующих столбцов — это делается руками осознанно.
 *
 * Запуск: кнопка «Обновить схему» в панели, либо напрямую /api/setup.php (нужен вход в панель)
 */

require_once __DIR__ . '/lib.php';

function setup_schema(): array
{
    return [
        'calendar_users' => [
            'create' => "CREATE TABLE IF NOT EXISTS `calendar_users` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT(20) NULL DEFAULT NULL,
                PRIMARY KEY (`id`) USING BTREE,
                UNIQUE INDEX `user_id` (`user_id`) USING BTREE
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB",
            'columns' => [
                'user_firstname'     => "TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'user_lastname'      => "TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'user_nickname'      => "TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'user_allowmessages' => "TINYINT(1) NOT NULL DEFAULT '1'",
                'user_metainfo'      => "TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'user_timezone'      => "VARCHAR(50) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'user_lastmessage'   => "DATE NULL DEFAULT NULL",
                'user_lastentry'     => "DATE NULL DEFAULT NULL",
                'user_entriestotal'  => "INT(11) NOT NULL DEFAULT '0'",
                'user_latestip'      => "TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'user_menu'          => "TEXT NOT NULL DEFAULT '0' COLLATE 'utf8mb4_unicode_ci'",
                'user_private'       => "TINYINT(1) NOT NULL DEFAULT '1'",
                'user_sharehash'     => "TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'user_shareimage'    => "TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'user_registerdate'  => "TIMESTAMP NULL DEFAULT current_timestamp()",
            ],
        ],
        'calendar_entries' => [
            'create' => "CREATE TABLE IF NOT EXISTS `calendar_entries` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT(20) NOT NULL,
                `entry_date` DATE NOT NULL,
                `mood_key` VARCHAR(25) NOT NULL COLLATE 'utf8mb4_unicode_ci',
                PRIMARY KEY (`id`) USING BTREE,
                UNIQUE INDEX `user_day_unique` (`user_id`, `entry_date`) USING BTREE,
                INDEX `idx_user_year` (`user_id`, `entry_date`) USING BTREE
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB",
            'columns' => [
                'description' => "TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci'",
                'created_at'  => "TIMESTAMP NULL DEFAULT current_timestamp()",
                'updated_at'  => "TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()",
                'alcohol'     => "TINYINT(1) NULL DEFAULT '0'",
                'sport'       => "TINYINT(1) NULL DEFAULT '0'",
                'sex'         => "TINYINT(1) NULL DEFAULT '0'",
                'friends'     => "TINYINT(1) NULL DEFAULT '0'",
                'romantic'    => "TINYINT(1) NULL DEFAULT '0'",
                'crying'      => "TINYINT(1) NULL DEFAULT '0'",
                'WomanDay'    => "TINYINT(1) NULL DEFAULT '0'",
            ],
        ],
        'app_settings' => [
            'create' => "CREATE TABLE IF NOT EXISTS `app_settings` (
                `k` VARCHAR(64) NOT NULL,
                `v` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
                `updated_at` TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`k`) USING BTREE
            ) COLLATE='utf8mb4_unicode_ci' ENGINE=InnoDB",
            'columns' => [],
        ],
    ];
}

/**
 * @return array{ok:bool,steps:array<int,array{what:string,status:string,detail:string}>}
 */
function setup_run(): array
{
    $steps = [];
    $add   = function (string $what, string $status, string $detail = '') use (&$steps) {
        $steps[] = ['what' => $what, 'status' => $status, 'detail' => $detail];
    };

    try {
        $pdo = db();
    } catch (Throwable $e) {
        $add('Подключение к базе', 'err', $e->getMessage());
        return ['ok' => false, 'steps' => $steps];
    }
    $add('Подключение к базе', 'ok', $GLOBALS['db_name'] . ' на ' . $GLOBALS['db_host']);

    foreach (setup_schema() as $table => $def) {
        $existed = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        try {
            $pdo->exec($def['create']);
        } catch (PDOException $e) {
            $add("Таблица $table", 'err', $e->getMessage());
            continue;
        }
        $add("Таблица $table", $existed ? 'ok' : 'added', $existed ? 'уже была' : 'создана');

        // Недостающие столбцы. Типы существующих не трогаем: молча менять тип опаснее, чем оставить как есть.
        $have = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $c) $have[$c['Field']] = true;
        foreach ($def['columns'] as $col => $ddl) {
            if (isset($have[$col])) continue;
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
                $add("Столбец $table.$col", 'added', 'добавлен');
            } catch (PDOException $e) {
                $add("Столбец $table.$col", 'err', $e->getMessage());
            }
        }
    }

    // Внешний ключ на пользователя — только если его ещё нет и обе таблицы пусты либо согласованы.
    try {
        $fk = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_entries'
                             AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->fetchColumn();
        if (!$fk) {
            $orphans = (int)$pdo->query("SELECT COUNT(*) FROM calendar_entries e
                                         LEFT JOIN calendar_users u ON u.user_id = e.user_id
                                         WHERE u.user_id IS NULL")->fetchColumn();
            if ($orphans > 0) {
                $add('Внешний ключ calendar_entries → calendar_users', 'warn',
                    "не добавлен: $orphans записей без пользователя. Почистите их и запустите снова");
            } else {
                $pdo->exec("ALTER TABLE calendar_entries ADD CONSTRAINT fk_user_id
                            FOREIGN KEY (user_id) REFERENCES calendar_users (user_id)
                            ON UPDATE RESTRICT ON DELETE RESTRICT");
                $add('Внешний ключ calendar_entries → calendar_users', 'added', 'добавлен');
            }
        } else {
            $add('Внешний ключ calendar_entries → calendar_users', 'ok', 'уже есть');
        }
    } catch (PDOException $e) {
        $add('Внешний ключ calendar_entries → calendar_users', 'warn', $e->getMessage());
    }

    // Досев настроек: существующие значения не перетираем.
    // Если в config.php остались старые переменные — забираем их значения, чтобы не вбивать руками.
    $legacy = array_filter([
        'socks_host'         => $GLOBALS['proxyConfig']['host'] ?? null,
        'socks_port'         => isset($GLOBALS['proxyConfig']['port']) ? (string)$GLOBALS['proxyConfig']['port'] : null,
        'socks_user'         => $GLOBALS['proxyConfig']['user'] ?? null,
        'socks_pass'         => $GLOBALS['proxyConfig']['pass'] ?? null,
        'tg_connect_timeout' => isset($GLOBALS['connectTimeout']) ? (string)$GLOBALS['connectTimeout'] : null,
        'tg_total_timeout'   => isset($GLOBALS['totalTimeout']) ? (string)$GLOBALS['totalTimeout'] : null,
        'tg_doh_url'         => $GLOBALS['dohUrl'] ?? null,
    ], fn($v) => $v !== null && $v !== '');

    $have = [];
    foreach ($pdo->query('SELECT k FROM app_settings') as $r) $have[$r['k']] = true;
    $seeded = 0;
    $ins    = $pdo->prepare('INSERT INTO app_settings (k, v) VALUES (?, ?)');
    foreach (settings_defaults() as $k => $v) {
        if (isset($have[$k])) continue;
        $ins->execute([$k, (string)($legacy[$k] ?? $v)]);
        $seeded++;
    }
    if ($legacy) $add('Перенос из config.php', 'ok', 'подхвачено значений: ' . count($legacy));
    $add('Настройки', $seeded ? 'added' : 'ok', $seeded ? "добавлено ключей: $seeded" : 'все ключи на месте');

    // Каталоги, в которые пишет приложение.
    foreach ([Log::dir() => 'журнал', __DIR__ . '/user_screens' => 'картинки календарей'] as $dir => $what) {
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $add("Каталог $what", is_writable($dir) ? 'ok' : 'err',
            is_writable($dir) ? $dir : "$dir недоступен на запись — проверьте права");
    }

    $ok = !array_filter($steps, fn($s) => $s['status'] === 'err');
    Log::info('admin', 'Проверка схемы БД', ['шагов' => count($steps), 'успех' => $ok]);
    return ['ok' => $ok, 'steps' => $steps];
}

// Прямой запуск — только для вошедшего в панель.
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    if (!admin_user()) {
        http_response_code(403);
        exit('Сначала войдите в панель: admin.php');
    }
    header('Content-Type: text/plain; charset=utf-8');
    foreach (setup_run()['steps'] as $s) {
        printf("[%-5s] %s%s\n", $s['status'], $s['what'], $s['detail'] ? ' — ' . $s['detail'] : '');
    }
}
