<?php
/**
 * Секреты и доступы. Файл в .gitignore и не должен попадать в репозиторий.
 * Всё, что можно менять на ходу (каналы связи, прокси, таймауты, вебхук, журнал),
 * живёт в базе и правится в панели: api/admin.php
 */

// --- База данных ---
$db_host = 'localhost';
$db_user = '045297031_misc';
$db_pass = 'D:CJ4tvbpbC7';
$db_name = 'fhnb16_misc';

// --- Telegram ---
$bot_token = '8179056348:AAE38UBIZ8TmB9ceZf3bhZ5mWmdoB54hjiY';

// --- Доступ в панель ---
// Telegram ID тех, кто может открыть панель из бота. Пока список пуст, вход из Telegram закрыт.
$admin_ids = [
    // 123456789,
];

// Пароль для входа с компьютера. Пустая строка — вход по паролю выключен.
// Хэш получить так:  php -r "echo password_hash('ваш пароль', PASSWORD_DEFAULT), PHP_EOL;"
$admin_password_hash = '';

// --- Кому фронтенд разрешено дёргать API ---
$allowed_origins = [
    'https://web.telegram.org',
    'https://bot.fhnb.ru',
];

// --- Старые настройки: перенесутся в базу при первом запуске установщика ---
// После того как значения появятся в панели, этот блок можно удалить.
$proxyConfig = [
    'host' => 'kz.wb-ozon96.ru',
    'port' => 995,
    'user' => 'tgproxy',
    'pass' => 'c10356ea43476127',
];
$connectTimeout = 15;
$totalTimeout   = 20;
$dohUrl         = 'https://doh.umbrella.com/dns-query';
