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

        foreach ($this->buildMappedFields($lead) as $fieldCode => $value) {
            if ($fieldCode !== '' && $value !== '') {
                $fields[$fieldCode] = $value;
            }
        }

        return [
            'fields' => $fields,
            'params' => [
                'REGISTER_SONET_EVENT' => 'Y',
            ],
        ];
    }

    private function buildMappedFields(array $lead): array
    {
        $quiz = is_array($lead['quiz'] ?? null) ? $lead['quiz'] : [];
        $result = is_array($lead['result'] ?? null) ? $lead['result'] : [];
        $page = is_array($lead['page'] ?? null) ? $lead['page'] : [];
        $utm = is_array($lead['utm'] ?? null) ? $lead['utm'] : [];

        $map = [
            'bitrix24_field_site_lead_id' => [(int)($lead['id'] ?? 0) > 0 ? (string)(int)$lead['id'] : '', 1000],
            'bitrix24_field_quiz_code' => [$quiz['code'] ?? '', 1000],
            'bitrix24_field_quiz_name' => [$quiz['name'] ?? '', 1000],
            'bitrix24_field_result_code' => [$result['code'] ?? '', 1000],
            'bitrix24_field_result_title' => [$result['title'] ?? '', 1000],
            'bitrix24_field_page_url' => [$page['url'] ?? '', 1000],
            'bitrix24_field_answers_text' => [$lead['answers_text'] ?? '', 10000],
            'bitrix24_field_utm_source' => [$utm['source'] ?? '', 1000],
            'bitrix24_field_utm_medium' => [$utm['medium'] ?? '', 1000],
            'bitrix24_field_utm_campaign' => [$utm['campaign'] ?? '', 1000],
            'bitrix24_field_utm_content' => [$utm['content'] ?? '', 1000],
            'bitrix24_field_utm_term' => [$utm['term'] ?? '', 1000],
        ];

        $fields = [];
        foreach ($map as $settingName => [$value, $limit]) {
            $fieldCode = $this->normalizeFieldCode(ModuleSettingsService::get($settingName));
            $fieldValue = $this->limitFieldValue($value, (int)$limit);
            if ($fieldCode !== '' && $fieldValue !== '') {
                $fields[$fieldCode] = $fieldValue;
            }
        }

        return $fields;
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

    private function normalizeFieldCode(mixed $value): string
    {
        $value = is_scalar($value) ? strtoupper(trim((string)$value)) : '';

        return preg_match('/^[A-Z0-9_]{3,100}$/', $value) === 1 ? $value : '';
    }

    private function limitFieldValue(mixed $value, int $limit = 1000): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? (string)mb_substr($value, 0, $limit) : substr($value, 0, $limit);
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
