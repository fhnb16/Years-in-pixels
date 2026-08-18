<?php
/**
 * Работа с Telegram Bot API через цепочку каналов.
 *
 * Каналы (порядок и состав задаются в панели, настройка tg_order):
 *   worker — реле Cloudflare вместо api.telegram.org
 *   direct — напрямую, при желании с резолвом через DoH
 *   socks  — основной SOCKS5
 *   socks2 — запасной SOCKS5
 *
 * Один и тот же tg_attempt() обслуживает и боевой путь, и кнопки проверки в панели,
 * поэтому «проверить канал» проверяет ровно то, чем ходит бот, а не похожий код рядом.
 */

require_once __DIR__ . '/lib.php';

function tg_channels(): array
{
    return [
        'worker' => 'Реле Cloudflare',
        'direct' => 'Напрямую',
        'socks'  => 'SOCKS5 основной',
        'socks2' => 'SOCKS5 запасной',
    ];
}

/** Настроен ли канал: незаполненный адрес реле или хост прокси — это не отказ сети, а незаполненное поле. */
function tg_channel_ready(string $channel): array
{
    switch ($channel) {
        case 'worker':
            $b = (string)Settings::get('tg_worker_base', '');
            if ($b === '') return [false, 'Не заполнен адрес реле Cloudflare'];
            if (!preg_match('~^https?://~', $b)) return [false, 'Адрес реле должен начинаться с https://'];
            return [true, ''];
        case 'socks':
            return Settings::get('socks_host', '') !== '' ? [true, ''] : [false, 'Не заполнен хост основного SOCKS5'];
        case 'socks2':
            return Settings::get('socks2_host', '') !== '' ? [true, ''] : [false, 'Не заполнен хост запасного SOCKS5'];
        case 'direct':
            return [true, ''];
    }
    return [false, 'Неизвестный канал'];
}

/**
 * Одна попытка вызова метода по конкретному каналу.
 * Никогда не бросает исключение — возвращает разобранный результат.
 *
 * @return array{ok:bool,channel:string,data:?array,reason:string,message:string,ms:int,http:int}
 */
function tg_attempt(string $channel, string $method, array $params = [], ?string $token = null, ?string $baseOverride = null): array
{
    $t0    = microtime(true);
    $token = $token ?: ($GLOBALS['bot_token'] ?? '');
    $fail  = function (string $reason, string $message, array $extra = []) use ($channel, $t0) {
        return array_merge([
            'ok' => false, 'channel' => $channel, 'data' => null,
            'reason' => $reason, 'message' => $message,
            'ms' => (int)round((microtime(true) - $t0) * 1000), 'http' => 0,
        ], $extra);
    };

    if ($token === '') return $fail('no_token', 'Токен бота не задан в config.php');

    // baseOverride передаёт установщик: настроек в базе ещё нет, а проверить связь надо уже сейчас.
    if ($baseOverride === null) {
        [$ready, $why] = tg_channel_ready($channel);
        if (!$ready) return $fail('not_configured', $why);
    }

    $base = rtrim((string)($baseOverride ?? ($channel === 'worker'
        ? Settings::get('tg_worker_base', '')
        : Settings::get('tg_api_base', 'https://api.telegram.org'))), '/');
    if ($base === '') return $fail('not_configured', 'Пустой адрес Bot API');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "$base/bot$token/$method",
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => Settings::int('tg_connect_timeout', 10),
        CURLOPT_TIMEOUT        => Settings::int('tg_total_timeout', 20),
        CURLOPT_NOSIGNAL       => true,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER     => ['Expect:'],
    ]);

    if ($channel === 'socks' || $channel === 'socks2') {
        $p = $channel === 'socks' ? '' : '2';
        curl_setopt_array($ch, [
            CURLOPT_PROXY     => Settings::get("socks{$p}_host", '') . ':' . Settings::int("socks{$p}_port", 1080),
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,   // резолв на стороне прокси
        ]);
        if (Settings::get("socks{$p}_user", '') !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD,
                Settings::get("socks{$p}_user", '') . ':' . Settings::get("socks{$p}_pass", ''));
        }
    } elseif (Settings::bool('tg_doh_enabled', true) && defined('CURLOPT_DOH_URL')) {
        curl_setopt($ch, CURLOPT_DOH_URL, Settings::get('tg_doh_url', 'https://cloudflare-dns.com/dns-query'));
    }

    $response = curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if ($response === false) {
        $reasons = [
            CURLE_COULDNT_RESOLVE_HOST  => ['dns',     'Имя не резолвится. Включите DoH или используйте реле'],
            CURLE_COULDNT_RESOLVE_PROXY => ['dns',     'Не резолвится хост прокси — проверьте его адрес'],
            CURLE_COULDNT_CONNECT       => ['connect', 'Соединение отклонено. Адрес и порт живы?'],
            CURLE_OPERATION_TIMEOUTED   => ['timeout', 'Истёк таймаут. Канал, скорее всего, режется на пути'],
            CURLE_RECV_ERROR            => ['reset',   'Соединение оборвано на приёме — типичный признак блокировки'],
            CURLE_SSL_CONNECT_ERROR     => ['tls',     'Не удалось установить TLS. Подмена сертификата или блокировка по SNI'],
        ];
        [$reason, $hint] = $reasons[$errno] ?? ['curl', 'Ошибка cURL'];
        return $fail($reason, "$hint (cURL $errno: $err)", ['ms' => $ms]);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return $fail('bad_json', $http === 200
            ? 'Ответ не разобрался как JSON — на пути прокси-заглушка или страница провайдера'
            : "Сервер ответил HTTP $http вместо ответа Bot API",
            ['ms' => $ms, 'http' => $http, 'sample' => mb_substr(strip_tags((string)$response), 0, 200)]);
    }

    if (empty($data['ok'])) {
        return $fail('api_error', 'Telegram отказал: ' . ($data['description'] ?? 'без описания'),
            ['ms' => $ms, 'http' => $http, 'data' => $data]);
    }

    return ['ok' => true, 'channel' => $channel, 'data' => $data, 'reason' => '', 'message' => 'ok', 'ms' => $ms, 'http' => $http];
}

/** Класс сохранён ради обратной совместимости с index.php и остальным кодом. */
class TelegramBot
{
    private $botToken;

    public function __construct($botToken = null)
    {
        $this->botToken = $botToken ?: ($GLOBALS['bot_token'] ?? null);
    }

    /** Перебирает включённые каналы по порядку. Бросает Exception, если не сработал ни один. */
    public function makeRequest($method, $params = [])
    {
        $order  = Settings::list('tg_order', ['worker', 'direct', 'socks', 'socks2']);
        $errors = [];

        foreach ($order as $channel) {
            $r = tg_attempt($channel, $method, $params, $this->botToken);
            if ($r['ok']) {
                Log::info('tg', "Метод $method выполнен", ['канал' => $channel, 'мс' => $r['ms'], 'попыток' => count($errors) + 1]);
                return $r['data'];
            }
            $errors[] = "$channel: {$r['message']}";
            // Отказ самого Telegram (неверный chat_id, бот заблокирован) — не сетевая проблема, другой канал не поможет.
            if ($r['reason'] === 'api_error') {
                Log::warn('tg', "Telegram отклонил метод $method", ['канал' => $channel, 'причина' => $r['message']]);
                throw new Exception($r['message']);
            }
            Log::warn('tg', "Канал не сработал на методе $method", [
                'канал' => $channel, 'причина' => $r['reason'], 'подробности' => $r['message'], 'мс' => $r['ms'],
            ]);
        }

        Log::error('tg', "Ни один канал не довёз метод $method", ['порядок' => $order, 'ошибки' => $errors]);
        throw new Exception("Telegram недоступен ни по одному каналу: " . implode(' | ', $errors));
    }

    public function getMe() { return $this->makeRequest('getMe'); }

    public function sendMessage($userId, $text, $options = [])
    {
        return $this->makeRequest('sendMessage', array_merge(['chat_id' => $userId, 'text' => $text], $options));
    }

    public function sendPhoto($userId, $photo, $caption = null, $options = [])
    {
        if (is_string($photo) && file_exists($photo)) $photo = new CURLFile(realpath($photo));
        return $this->makeRequest('sendPhoto', array_merge(
            ['chat_id' => $userId, 'photo' => $photo, 'caption' => $caption], $options));
    }

    /**
     * Три попытки доставить картинку, от дешёвой к дорогой:
     *   1. ссылкой — Telegram скачивает файл сам через инбаунд-реле, наш канал наружу почти не нагружен;
     *   2. загрузкой — файл уходит телом запроса, работает даже когда наш адрес снаружи недоступен,
     *      но тащит несколько мегабайт через тот же канал, что и обычные вызовы;
     *   3. текстом со ссылкой — чтобы человек хотя бы забрал картинку руками.
     *
     * @return array{ok:bool,via:string,message:string,tries:array}
     */
    public function sendPhotoBestEffort($userId, string $localPath, string $publicUrl, ?string $caption = null, array $options = []): array
    {
        $tries = [];

        if ($publicUrl !== '') {
            try {
                $this->makeRequest('sendPhoto', array_merge(
                    ['chat_id' => $userId, 'photo' => $publicUrl, 'caption' => $caption], $options));
                Log::info('tg', 'Картинка отправлена ссылкой', ['user_id' => $userId, 'url' => $publicUrl]);
                return ['ok' => true, 'via' => 'url', 'message' => 'отправлено ссылкой', 'tries' => $tries];
            } catch (Exception $e) {
                $tries[] = 'ссылкой: ' . $e->getMessage();
                // Самая частая причина — Telegram не смог скачать файл, то есть инбаунд-реле не отдаёт uploads.
                Log::warn('tg', 'Не вышло отправить картинку ссылкой, пробуем загрузкой', [
                    'user_id' => $userId, 'url' => $publicUrl, 'причина' => $e->getMessage(),
                ]);
            }
        } else {
            $tries[] = 'ссылкой: публичный адрес файлов не задан';
        }

        if (is_file($localPath)) {
            try {
                $this->makeRequest('sendPhoto', array_merge(
                    ['chat_id' => $userId, 'photo' => new CURLFile(realpath($localPath)), 'caption' => $caption], $options));
                Log::info('tg', 'Картинка отправлена загрузкой файла', [
                    'user_id' => $userId, 'кб' => (int)round(filesize($localPath) / 1024),
                ]);
                return ['ok' => true, 'via' => 'upload', 'message' => 'отправлено загрузкой файла', 'tries' => $tries];
            } catch (Exception $e) {
                $tries[] = 'загрузкой: ' . $e->getMessage();
                Log::warn('tg', 'Не вышло отправить картинку загрузкой', ['user_id' => $userId, 'причина' => $e->getMessage()]);
            }
        } else {
            $tries[] = 'загрузкой: файла нет на диске (' . $localPath . ')';
        }

        if ($publicUrl !== '') {
            try {
                $this->sendMessage($userId, 'Ваше изображение календаря <a href="' . htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') . '">готово</a>!',
                    ['parse_mode' => 'HTML']);
                Log::warn('tg', 'Картинка не ушла, отправили ссылку текстом', ['user_id' => $userId, 'попытки' => $tries]);
                return ['ok' => true, 'via' => 'link', 'message' => 'картинка не прошла, отправлена ссылка текстом', 'tries' => $tries];
            } catch (Exception $e) {
                $tries[] = 'текстом: ' . $e->getMessage();
            }
        }

        Log::error('tg', 'Картинку доставить не удалось ничем', ['user_id' => $userId, 'попытки' => $tries]);
        return ['ok' => false, 'via' => '', 'message' => 'ни один способ не сработал', 'tries' => $tries];
    }

    public function sendPhotoUrl($userId, $photoUrl, $caption = null, $options = [])
    {
        return $this->makeRequest('sendPhoto', array_merge(
            ['chat_id' => $userId, 'photo' => $photoUrl, 'caption' => $caption], $options));
    }

    public function sendPhotoWithButtons($userId, $photoUrl, $caption = null, $inlineKeyboard = [], $options = [])
    {
        return $this->makeRequest('sendPhoto', array_merge([
            'chat_id'      => $userId,
            'photo'        => $photoUrl . '?rnd=' . time(),
            'caption'      => $caption,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ], $options));
    }

    public function sendMessageWithButtons($userId, $text, $inlineKeyboard = [], $options = [])
    {
        return $this->makeRequest('sendMessage', array_merge([
            'chat_id'      => $userId,
            'text'         => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ], $options));
    }

    public function sendDocument($userId, $documentUrl, $caption = null, $options = [])
    {
        return $this->makeRequest('sendDocument', array_merge(
            ['chat_id' => $userId, 'document' => $documentUrl, 'caption' => $caption], $options));
    }

    public function getUserProfilePhotos($userId, $offset = 0, $limit = 100)
    {
        return $this->makeRequest('getUserProfilePhotos', ['user_id' => $userId, 'offset' => $offset, 'limit' => $limit]);
    }
}

/** Упрощённая отправка изображения — оставлена для обратной совместимости. */
function sendUserImage($userId, $imageUrl)
{
    try {
        $result = (new TelegramBot())->sendPhoto($userId, $imageUrl);
        return ['success' => true, 'message' => 'Изображение успешно отправлено', 'result' => $result];
    } catch (Exception $e) {
        Log::error('tg', 'Не удалось отправить изображение', ['user_id' => $userId, 'ошибка' => $e->getMessage()]);
        return ['success' => false, 'message' => 'Ошибка отправки изображения: ' . $e->getMessage()];
    }
}
