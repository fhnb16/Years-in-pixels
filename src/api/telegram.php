<?php

include_once("config.php");

/**
 * Класс для работы с Telegram Bot API
 */
class TelegramBot {
    private $botToken;
    private $apiUrl;
    
    public function __construct($botToken = null) {
        $this->botToken = $botToken ?: $GLOBALS['bot_token'] ?? null;
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
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
public function makeRequest($method, $params = []) {
    $url = $this->apiUrl . '/' . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    
    // ВАЖНО: Для отправки файлов (CURLFile) передаем массив КАК ЕСТЬ.
    // PHP сам выставит Content-Type: multipart/form-data
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    // Для дебага: CURLFile нельзя красиво залогировать через json_encode,
    // он превращается в тыкву {}. Поэтому логируем только если это не файл.
    $debugParams = array_map(function($item) {
        return ($item instanceof CURLFile) ? "[Local File: {$item->getFilename()}]" : $item;
    }, $params);
    
    error_log("=== TG DEBUG ===");
    error_log("Method: " . $method);
    error_log("Params: " . json_encode($debugParams, JSON_UNESCAPED_UNICODE));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("Ошибка cURL: " . $error);
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