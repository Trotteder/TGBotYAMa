<?php

class TelegramAPI {
    private $token;
    private $apiUrl;

    public function __construct($token) {
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$token}/";
    }

    public function sendMessage($chatId, $text, $replyMarkup = null) {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->makeRequest('sendMessage', $data);
    }

    public function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
        $data = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->makeRequest('editMessageText', $data);
    }

    public function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false) {
        $data = [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert
        ];

        return $this->makeRequest('answerCallbackQuery', $data);
    }

    public function sendPhoto($chatId, $photoPath, $caption = '', $replyMarkup = null) {
        $url = $this->apiUrl . 'sendPhoto';
        
        $data = [
            'chat_id' => $chatId,
            'photo' => new CURLFile($photoPath),
            'caption' => $caption,
            'parse_mode' => 'HTML'
        ];
        
        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }

    public function deleteMessage($chatId, $messageId) {
        $data = [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ];

        return $this->makeRequest('deleteMessage', $data);
    }

    public function getMe() {
        return $this->makeRequest('getMe');
    }

    private function makeRequest($method, $data = []) {
        $url = $this->apiUrl . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            $this->logApiError("cURL error #$errno: $error (method: $method)");
            return false;
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);
        
        // Проверяем на ошибки, но игнорируем "message not found" - это нормальная ситуация
        if ($httpCode != 200) {
            $isMessageNotFound = isset($result['description']) && 
                (strpos($result['description'], 'message to edit not found') !== false ||
                 strpos($result['description'], 'message to delete not found') !== false ||
                 strpos($result['description'], 'message can\'t be edited') !== false);
            
            if (!$isMessageNotFound) {
                $this->logApiError("HTTP $httpCode (method: $method), Response: $response");
            }
            return $result; // Возвращаем результат чтобы код мог проверить error_code
        }
        
        if (isset($result['ok']) && !$result['ok']) {
            $isMessageNotFound = isset($result['description']) && 
                (strpos($result['description'], 'message to edit not found') !== false ||
                 strpos($result['description'], 'message to delete not found') !== false ||
                 strpos($result['description'], 'message can\'t be edited') !== false);
            
            if (!$isMessageNotFound) {
                $this->logApiError("Telegram API returned ok=false (method: $method): " . json_encode($result));
            }
        }
        
        return $result;
    }
    
    private function logApiError($message) {
        $logFile = __DIR__ . '/telegram_api.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    }
}
