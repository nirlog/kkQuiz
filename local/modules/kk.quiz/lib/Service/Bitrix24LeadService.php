<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

final class Bitrix24LeadService
{
    private const METHOD = 'crm.lead.add.json';

    public function send(array $payload): array
    {
        $startedAt = microtime(true);

        if (!ModuleSettingsService::getBool('bitrix24_enabled')) {
            return $this->buildResult(true, true, 0, 'skipped', '', 'BITRIX24_DISABLED', '', '', '', '', 0);
        }

        $url = $this->normalizeUrl(ModuleSettingsService::get('bitrix24_webhook_url'));
        if ($url === '') {
            return $this->buildResult(false, false, 0, 'ERROR', '', 'BITRIX24_WEBHOOK_URL_EMPTY', '', '', '', '', 0);
        }

        $requestUrl = $this->buildMethodUrl($url);
        $maskedUrl = $this->maskWebhookUrl($requestUrl);
        $requestBody = '';

        try {
            $requestPayload = $this->buildRequestPayload($payload);
            $requestBody = Json::encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $httpClient = new HttpClient([
                'socketTimeout' => 10,
                'streamTimeout' => 10,
            ]);
            $httpClient->setHeader('Content-Type', 'application/json', true);

            $response = (string)$httpClient->post($requestUrl, $requestBody);
            $status = (int)$httpClient->getStatus();
            $durationMs = $this->getDurationMs($startedAt);

            if ($status < 200 || $status > 299) {
                return $this->buildResult(false, false, $status, 'HTTP_' . $status, '', 'HTTP_' . $status, $maskedUrl, $requestBody, $response, '', $durationMs);
            }

            $decoded = [];
            try {
                $decoded = Json::decode($response);
            } catch (\Throwable) {
            }

            if (is_array($decoded) && array_key_exists('result', $decoded)) {
                return $this->buildResult(true, false, $status, 'HTTP_' . $status, (string)$decoded['result'], '', $maskedUrl, $requestBody, $response, '', $durationMs);
            }

            $error = 'BITRIX24_ERROR';
            if (is_array($decoded)) {
                $code = trim((string)($decoded['error'] ?? ''));
                $description = trim((string)($decoded['error_description'] ?? ''));
                $error = trim($code . ($description !== '' ? ': ' . $description : ''));
                $error = $error !== '' ? $error : 'BITRIX24_ERROR';
            }

            return $this->buildResult(false, false, $status, 'BITRIX24_ERROR', '', $error, $maskedUrl, $requestBody, $response, '', $durationMs);
        } catch (\Throwable $exception) {
            return $this->buildResult(false, false, 0, 'ERROR', '', $exception->getMessage() !== '' ? $exception->getMessage() : 'BITRIX24_SEND_FAILED', $maskedUrl, $requestBody, '', '', $this->getDurationMs($startedAt));
        }
    }

    private function buildRequestPayload(array $payload): array
    {
        $lead = is_array($payload['lead'] ?? null) ? $payload['lead'] : [];
        $quiz = is_array($lead['quiz'] ?? null) ? $lead['quiz'] : [];
        $result = is_array($lead['result'] ?? null) ? $lead['result'] : [];
        $client = is_array($lead['client'] ?? null) ? $lead['client'] : [];

        $fields = [
            'TITLE' => $this->buildTitle($quiz, $result),
            'COMMENTS' => $this->buildComments($lead),
        ];

        foreach ([
            'NAME' => $client['name'] ?? '',
            'SOURCE_ID' => ModuleSettingsService::get('bitrix24_source_id'),
        ] as $key => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        $assignedById = (int)ModuleSettingsService::get('bitrix24_assigned_by_id');
        if ($assignedById > 0) {
            $fields['ASSIGNED_BY_ID'] = $assignedById;
        }

        $phone = trim((string)($client['phone'] ?? ''));
        if ($phone !== '') {
            $fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
        }

        $email = trim((string)($client['email'] ?? ''));
        if ($email !== '') {
            $fields['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
        }

        return [
            'fields' => $fields,
            'params' => [
                'REGISTER_SONET_EVENT' => 'Y',
            ],
        ];
    }

    private function buildTitle(array $quiz, array $result): string
    {
        $quizName = trim((string)($quiz['name'] ?? ''));
        $resultTitle = trim((string)($result['title'] ?? ''));
        $title = 'Заявка с квиза' . ($quizName !== '' ? ': ' . $quizName : '');

        return $resultTitle !== '' ? $title . ' — ' . $resultTitle : $title;
    }

    private function buildComments(array $lead): string
    {
        $quiz = is_array($lead['quiz'] ?? null) ? $lead['quiz'] : [];
        $result = is_array($lead['result'] ?? null) ? $lead['result'] : [];
        $client = is_array($lead['client'] ?? null) ? $lead['client'] : [];
        $page = is_array($lead['page'] ?? null) ? $lead['page'] : [];
        $utm = is_array($lead['utm'] ?? null) ? $lead['utm'] : [];

        return implode("\n", [
            'Квиз: ' . (string)($quiz['name'] ?? ''),
            'Код квиза: ' . (string)($quiz['code'] ?? ''),
            'Результат: ' . (string)($result['title'] ?? ''),
            '',
            'Клиент:',
            'Имя: ' . (string)($client['name'] ?? ''),
            'Телефон: ' . (string)($client['phone'] ?? ''),
            'Email: ' . (string)($client['email'] ?? ''),
            'Мессенджер: ' . (string)($client['messenger'] ?? ''),
            'Комментарий: ' . (string)($client['comment'] ?? ''),
            '',
            'Ответы:',
            (string)($lead['answers_text'] ?? ''),
            '',
            'Страница:',
            (string)($page['url'] ?? ''),
            '',
            'UTM:',
            'source: ' . (string)($utm['source'] ?? ''),
            'medium: ' . (string)($utm['medium'] ?? ''),
            'campaign: ' . (string)($utm['campaign'] ?? ''),
            'content: ' . (string)($utm['content'] ?? ''),
            'term: ' . (string)($utm['term'] ?? ''),
            '',
            'ID заявки на сайте: ' . (string)($lead['id'] ?? ''),
        ]);
    }

    private function normalizeUrl(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        if ($value === '' || preg_match('#^https?://#i', $value) !== 1) {
            return '';
        }

        return $value;
    }

    private function buildMethodUrl(string $url): string
    {
        $url = rtrim($url, '/');
        if (preg_match('#/' . preg_quote(self::METHOD, '#') . '$#i', $url) === 1) {
            return $url;
        }

        return $url . '/' . self::METHOD;
    }

    private function maskWebhookUrl(string $url): string
    {
        return preg_replace('#(/rest/\d+/)[^/]+/#i', '$1***/', $url) ?? $url;
    }

    private function buildResult(
        bool $success,
        bool $skipped,
        int $status,
        string $statusLabel,
        string $externalId,
        string $error,
        string $requestUrl,
        string $requestBody,
        string $response,
        string $reason,
        int $durationMs
    ): array {
        return [
            'success' => $success,
            'skipped' => $skipped,
            'reason' => $reason !== '' ? $reason : ($skipped ? $error : ''),
            'status' => $status,
            'status_label' => $statusLabel,
            'external_id' => $externalId,
            'response' => $this->limit($response, 1000),
            'error' => $this->limit($error, 1000),
            'request_url' => $requestUrl,
            'request_body' => $requestBody,
            'duration_ms' => $durationMs,
        ];
    }

    private function getDurationMs(float $startedAt): int
    {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }

    private function limit(mixed $value, int $limit): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';

        return function_exists('mb_substr') ? (string)mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
