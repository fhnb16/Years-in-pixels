<?php

include_once("config.php");

/**
 * Класс для работы с Telegram Bot API
 */
class TelegramBot {
    private $botToken;
    private $apiUrl;
    private $proxyConfig;
    private $connectTimeout;
    private $totalTimeout;
    private $dohUrl;
    
    public function __construct($botToken = null, $proxyConfig = null, $connectTimeout = null, $totalTimeout = null, $dohUrl = null) {
        $this->botToken = $botToken ?: $GLOBALS['bot_token'] ?? null;
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
        $this->proxyConfig = $proxyConfig ?: $GLOBALS['proxyConfig'] ?? null;
        $this->connectTimeout = $connectTimeout ?: $GLOBALS['connectTimeout'] ?? null;
        $this->totalTimeout = $totalTimeout ?: $GLOBALS['totalTimeout'] ?? null;
        $this->dohUrl = $dohUrl ?: $GLOBALS['dohUrl'] ?? null;
    }
    
    /**
     * Отправка изображения пользователю
     */
    public function sendPhoto($userId, $photo, $caption = null, $options = []) {
        // Если передан путь к локальному файлу, оборачиваем в CURLFile
        if (file_exists($photo)) {
            $photo = new CURLFile(realpath($photo));
        }

        $params = array_merge([
            'chat_id' => $userId,
            'photo' => $photo,
            'caption' => $caption
        ], $options);
        
        return $this->makeRequest('sendPhoto', $params);
    }

    /**
     * Отправка изображения пользователю по url
     */
    public function sendPhotoUrl($userId, $photoUrl, $caption = null, $options = []) {
        $params = array_merge([
            'chat_id' => $userId,
            'photo' => $photoUrl,
            'caption' => $caption
        ], $options);
        
        return $this->makeRequest('sendPhoto', $params);
    }
    
    /**
     * Отправка текстового сообщения
     */
    public function sendMessage($userId, $text, $options = []) {
        $params = array_merge([
            'chat_id' => $userId,
            'text' => $text
        ], $options);
        
        return $this->makeRequest('sendMessage', $params);
    }
    
    /**
     * Отправка изображения с кнопками
     */
    public function sendPhotoWithButtons($userId, $photoUrl, $caption = null, $inlineKeyboard = [], $options = []) {
        $params = array_merge([
            'chat_id' => $userId,
            'photo' => $photoUrl . "?rnd=" . time(),
            'caption' => $caption,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard])
        ], $options);
        
        return $this->makeRequest('sendPhoto', $params);
    }
    
    /**
     * Отправка сообщения с кнопками
     */
    public function sendMessageWithButtons($userId, $text, $inlineKeyboard = [], $options = []) {
        $params = array_merge([
            'chat_id' => $userId,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard])
        ], $options);
        
        return $this->makeRequest('sendMessage', $params);
    }
    
    /**
     * Отправка документа
     */
    public function sendDocument($userId, $documentUrl, $caption = null, $options = []) {
        $params = array_merge([
            'chat_id' => $userId,
            'document' => $documentUrl,
            'caption' => $caption
        ], $options);
        
        return $this->makeRequest('sendDocument', $params);
    }
    
    /**
     * Отправка документа альтернативная через мультипарт через курл
     */
    public function sendDocumentMultipart($userId, $documentUrl) {
        $params = array_merge([
            'chat_id' => $userId,
            'document' => str_replace('https://bot.fhnb.ru/pixels/api/', './', $documentUrl)
        ]);
        
        return $this->makeRequest('sendDocument', $params);
    }
    
    /**
     * Универсальный метод для выполнения запросов к Telegram API
     */
public function makeRequest($method, $params = [], $useProxy = false) {
    $url = $this->apiUrl . '/' . $method;
    
    $ch = curl_init();
    // Базовые опции cURL
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $this->totalTimeout,
        CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
        CURLOPT_NOSIGNAL => true,
        
        // DNS over HTTPS (cURL >= 7.62.0 + libcurl с поддержкой DoH)
        CURLOPT_DOH_URL => $this->dohUrl,
        
        // Обходим блокировки: игнорируем резолв через системный DNS
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // иногда помогает при блокировках по IPv6
        
        // Заголовки (опционально, но полезно)
        CURLOPT_HTTPHEADER => [
            'Expect:', // убираем Expect: 100-continue для совместимости
        ],
    ]);
    
    // Если используем прокси — добавляем настройки
    if ($useProxy) {
        curl_setopt_array($ch, [
            CURLOPT_PROXY => $this->proxyConfig['host'] . ':' . $this->proxyConfig['port'],
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME, // SOCKS5 с резолвом на стороне прокси
            CURLOPT_PROXYUSERPWD => $this->proxyConfig['user'] . ':' . $this->proxyConfig['pass'],
            
            // Важно: не резолвить хост локально при использовании прокси
            CURLOPT_PROXY_TRANSFER_MODE => true,
        ]);
    }
    
    // Дебаг-логирование
    $debugParams = array_map(function($item) {
        return ($item instanceof CURLFile) ? "[Local File: {$item->getFilename()}]" : $item;
    }, $params);
    
    error_log("=== TG DEBUG ===");
    error_log("Method: " . $method);
    error_log("Proxy: " . ($useProxy ? "YES" : "NO"));
    error_log("Params: " . json_encode($debugParams, JSON_UNESCAPED_UNICODE));
    
    // Выполняем запрос
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    // Логика повторного вызова: если таймаут подключения или ошибка резолва — пробуем через прокси
    $retryErrors = [
        CURLE_OPERATION_TIMEOUTED, // таймаут
        CURLE_COULDNT_CONNECT,     // не удалось подключиться
        CURLE_COULDNT_RESOLVE_HOST,// не резолвится хост
        CURLE_RECV_ERROR,          // обрыв соединения (часто при блокировках)
    ];
    
    if (!$useProxy && in_array($curlErrno, $retryErrors) && ($response === false || empty($result) || !isset($result['ok']))) {
        error_log("=== TG RETRY === Перепробуем через SOCKS5 proxy (ошибка: $curlError, код: $curlErrno)");
        // Рекурсивный вызов с флагом прокси
        return $this->makeRequest($method, $params, true);
    }
    
    // Обработка ошибок
    if ($response === false) {
        throw new Exception("Ошибка cURL: " . $curlError . " (errno: $curlErrno)");
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200 || (isset($result['ok']) && !$result['ok'])) {
        $description = $result['description'] ?? 'Unknown error';
        error_log("=== TG ERROR ===\nCode: $httpCode\nResponse: $response");
        throw new Exception("Telegram API error: " . $description);
    }
    
    return $result;
}
    
    /**
     * Получение информации о боте
     */
    public function getMe() {
        return $this->makeRequest('getMe');
    }
    
    /**
     * Получение информации о пользователе
     */
    public function getUserProfilePhotos($userId, $offset = 0, $limit = 100) {
        $params = [
            'user_id' => $userId,
            'offset' => $offset,
            'limit' => $limit
        ];
        
        return $this->makeRequest('getUserProfilePhotos', $params);
    }
}

/**
 * Упрощенная функция для отправки изображения (для обратной совместимости)
 */
function sendUserImage($userId, $imageUrl) {
    try {
        $telegram = new TelegramBot();
        $result = $telegram->sendPhoto($userId, $imageUrl);
        return [
            'success' => true,
            'message' => 'Изображение успешно отправлено',
            'result' => $result
        ];
    } catch (Exception $e) {
        error_log("Ошибка отправки изображения: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Ошибка отправки изображения: ' . $e->getMessage()
        ];
    }
}

?>