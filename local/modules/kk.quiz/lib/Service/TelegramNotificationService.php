<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

final class TelegramNotificationService
{
    public function sendTestMessage(array $settings): array
    {
        $message = 'Тестовое сообщение KK Quiz. Telegram-уведомления настроены корректно.';

        return $this->sendMessage($settings, $message);
    }

    public function sendMessage(array $settings, string $text): array
    {
        $botToken = $this->normalizeBotToken($settings['telegram_bot_token'] ?? '');
        $chatId = $this->normalizeChatId($settings['telegram_chat_id'] ?? '');
        $messageThreadId = $this->normalizeMessageThreadId($settings['telegram_message_thread_id'] ?? '');
        $proxyUrl = $this->normalizeProxyUrl($settings['telegram_proxy_url'] ?? '');

        if ($botToken === '') {
            return ['success' => false, 'message' => 'Не указан или некорректен Telegram Bot Token'];
        }

        if ($chatId === '') {
            return ['success' => false, 'message' => 'Не указан или некорректен Telegram Chat ID'];
        }

        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'message' => 'Пустой текст сообщения'];
        }

        try {
            return $proxyUrl !== ''
                ? $this->sendViaCurl($botToken, $chatId, $text, $proxyUrl, $messageThreadId)
                : $this->sendViaHttpClient($botToken, $chatId, $text, $messageThreadId);
        } catch (\Throwable) {
            return ['success' => false, 'message' => 'Ошибка отправки Telegram-сообщения'];
        }
    }

    private function sendViaHttpClient(string $botToken, string $chatId, string $text, int $messageThreadId = 0): array
    {
        $httpClient = new HttpClient([
            'socketTimeout' => 10,
            'streamTimeout' => 10,
        ]);

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ];

        if ($messageThreadId > 0) {
            $payload['message_thread_id'] = (string)$messageThreadId;
        }

        $response = $httpClient->post(
            'https://api.telegram.org/bot' . $botToken . '/sendMessage',
            $payload
        );

        return $this->handleTelegramResponse($response, (int)$httpClient->getStatus());
    }

    private function sendViaCurl(string $botToken, string $chatId, string $text, string $proxyUrl, int $messageThreadId = 0): array
    {
        if (!extension_loaded('curl')) {
            return ['success' => false, 'message' => 'Для отправки через proxy требуется PHP-расширение curl'];
        }

        $proxy = $this->parseProxyUrl($proxyUrl);
        if ($proxy === null) {
            return ['success' => false, 'message' => 'Некорректный proxy URL'];
        }

        $ch = curl_init('https://api.telegram.org/bot' . $botToken . '/sendMessage');
        if ($ch === false) {
            return ['success' => false, 'message' => 'Не удалось инициализировать cURL'];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ];

        if ($messageThreadId > 0) {
            $payload['message_thread_id'] = (string)$messageThreadId;
        }

        $postFields = http_build_query($payload);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROXY => $proxy['host'] . ':' . $proxy['port'],
        ]);

        if ($proxy['type'] === 'socks5') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }

        if ($proxy['auth'] !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['auth']);
        }

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'message' => $curlError !== '' ? 'Ошибка cURL при отправке через proxy' : 'Ошибка отправки через proxy',
            ];
        }

        return $this->handleTelegramResponse((string)$response, $status);
    }

    private function normalizeBotToken(mixed $value): string
    {
        $value = trim((string)$value);

        return preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $value) === 1 ? $value : '';
    }

    private function normalizeChatId(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^@[A-Za-z0-9_]{5,}$/', $value) === 1) {
            return $value;
        }

        return '';
    }


    private function normalizeMessageThreadId(mixed $value): int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            return 0;
        }

        return max(0, (int)$value);
    }

    private function normalizeProxyUrl(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        return $this->parseProxyUrl($value) !== null ? $value : '';
    }

    private function parseProxyUrl(string $proxyUrl): ?array
    {
        $parts = parse_url($proxyUrl);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        $port = (int)($parts['port'] ?? 0);

        if (!in_array($scheme, ['http', 'https', 'socks5'], true)) {
            return null;
        }

        if ($host === '' || $port <= 0 || $port > 65535) {
            return null;
        }

        $user = isset($parts['user']) ? rawurldecode((string)$parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode((string)$parts['pass']) : '';
        $auth = $user !== '' ? $user . ':' . $pass : '';

        return [
            'type' => $scheme,
            'host' => $host,
            'port' => $port,
            'auth' => $auth,
        ];
    }

    private function handleTelegramResponse(string|false $response, int $status): array
    {
        if ($response === false || $response === '') {
            return ['success' => false, 'message' => 'Пустой ответ Telegram API'];
        }

        if ($status < 200 || $status >= 300) {
            return ['success' => false, 'message' => 'Telegram API вернул HTTP ' . $status];
        }

        try {
            $data = Json::decode((string)$response);
        } catch (\Throwable) {
            return ['success' => false, 'message' => 'Некорректный JSON-ответ Telegram API'];
        }

        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            $description = is_array($data) ? trim((string)($data['description'] ?? '')) : '';

            return [
                'success' => false,
                'message' => $description !== ''
                    ? 'Telegram API: ' . $description
                    : 'Telegram API не подтвердил отправку сообщения',
            ];
        }

        return ['success' => true, 'message' => 'Тестовое сообщение отправлено'];
    }
}
