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
    public function sendPhoto($userId, $photoUrl, $caption = null, $options = []) {
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
            'photo' => $photoUrl,
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
     * Универсальный метод для выполнения запросов к Telegram API
     */
    public function makeRequest($method, $params = []) {
        $url = $this->apiUrl . '/' . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception("Ошибка cURL: " . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Telegram API вернул код ошибки: " . $httpCode);
        }
        
        $result = json_decode($response, true);
        
        if (!$result) {
            throw new Exception("Невозможно распарсить ответ от Telegram API");
        }
        
        if (!$result['ok']) {
            throw new Exception("Telegram API ошибка: " . ($result['description'] ?? 'Неизвестная ошибка'));
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