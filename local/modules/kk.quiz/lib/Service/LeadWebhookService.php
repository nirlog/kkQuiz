<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

final class LeadWebhookService
{
    private const ALLOWED_TIMEOUTS = [3, 5, 10, 15];

    public function send(array $payload): array
    {
        $startedAt = microtime(true);

        if (!ModuleSettingsService::getBool('webhook_enabled')) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'WEBHOOK_DISABLED',
                'status' => 0,
                'status_label' => 'skipped',
                'response' => '',
                'error' => 'WEBHOOK_DISABLED',
                'request_url' => '',
                'request_body' => '',
                'duration_ms' => 0,
            ];
        }

        $url = $this->normalizeUrl(ModuleSettingsService::get('webhook_url'));
        if ($url === '') {
            return [
                'success' => false,
                'skipped' => false,
                'status' => 0,
                'status_label' => 'ERROR',
                'response' => '',
                'error' => 'WEBHOOK_URL_EMPTY',
                'request_url' => '',
                'request_body' => '',
                'duration_ms' => 0,
            ];
        }

        $jsonBody = '';

        try {
            $jsonBody = Json::encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $timeout = $this->getTimeout();
            $httpClient = new HttpClient([
                'socketTimeout' => $timeout,
                'streamTimeout' => $timeout,
            ]);
            $httpClient->setHeader('Content-Type', 'application/json', true);
            $httpClient->setHeader('X-KK-Quiz-Event', 'lead.created', true);
            $httpClient->setHeader('X-KK-Quiz-Version', '1', true);

            $secret = trim(ModuleSettingsService::get('webhook_secret'));
            if ($secret !== '') {
                $httpClient->setHeader('X-KK-Quiz-Signature', hash_hmac('sha256', $jsonBody, $secret), true);
                $httpClient->setHeader('X-KK-Quiz-Signature-Alg', 'sha256', true);
            }

            $response = (string)$httpClient->post($url, $jsonBody);
            $status = (int)$httpClient->getStatus();
            $success = $status >= 200 && $status <= 299;

            return [
                'success' => $success,
                'skipped' => false,
                'status' => $status,
                'status_label' => 'HTTP_' . $status,
                'response' => $this->limit($response),
                'error' => $success ? '' : 'HTTP_' . $status,
                'request_url' => $url,
                'request_body' => $jsonBody,
                'duration_ms' => $this->getDurationMs($startedAt),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'skipped' => false,
                'status' => 0,
                'status_label' => 'ERROR',
                'response' => '',
                'error' => $this->limit($exception->getMessage() !== '' ? $exception->getMessage() : 'WEBHOOK_SEND_FAILED'),
                'request_url' => $url,
                'request_body' => $jsonBody,
                'duration_ms' => $this->getDurationMs($startedAt),
            ];
        }
    }

    private function normalizeUrl(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        if ($value === '' || preg_match('#^https?://#i', $value) !== 1) {
            return '';
        }

        return $value;
    }

    private function getTimeout(): int
    {
        $timeout = (int)ModuleSettingsService::get('webhook_timeout');

        return in_array($timeout, self::ALLOWED_TIMEOUTS, true) ? $timeout : 5;
    }

    private function getDurationMs(float $startedAt): int
    {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }

    private function limit(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';

        return function_exists('mb_substr') ? (string)mb_substr($value, 0, 1000) : substr($value, 0, 1000);
    }
}
