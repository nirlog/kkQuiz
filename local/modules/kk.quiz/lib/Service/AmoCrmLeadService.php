<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

final class AmoCrmLeadService
{
    private AmoCrmTokenService $tokenService;

    public function __construct(?AmoCrmTokenService $tokenService = null)
    {
        $this->tokenService = $tokenService ?? new AmoCrmTokenService();
    }

    public function send(array $payload): array
    {
        $startedAt = microtime(true);

        if (!ModuleSettingsService::getBool('amocrm_enabled')) {
            return $this->buildResult(true, true, 0, 'skipped', '', '', 'AMOCRM_DISABLED', '', '', '', $this->getDurationMs($startedAt), ['reason' => 'AMOCRM_DISABLED']);
        }

        $baseUrl = $this->tokenService->getBaseUrl();
        if (!$this->hasCompleteSettings($baseUrl)) {
            return $this->buildResult(false, false, 0, 'ERROR', '', '', 'AMOCRM_SETTINGS_INCOMPLETE', '', '', '', $this->getDurationMs($startedAt));
        }

        $requestUrl = $baseUrl . '/api/v4/leads/complex';
        $requestPayload = $this->buildRequestPayload($payload);
        $requestBody = Json::encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = $this->postJson($requestUrl, $requestBody, $this->tokenService->getAccessToken(), $startedAt);
        if ((int)($result['status'] ?? 0) === 401) {
            $refresh = $this->tokenService->refreshAccessToken();
            if (($refresh['success'] ?? false) !== true) {
                return $this->buildResult(false, false, (int)($refresh['status'] ?? 0), 'ERROR', '', '', 'AMOCRM_TOKEN_REFRESH_FAILED', $requestUrl, $requestBody, (string)($refresh['response'] ?? ''), $this->getDurationMs($startedAt));
            }
            $result = $this->postJson($requestUrl, $requestBody, $this->tokenService->getAccessToken(), $startedAt);
        }

        if (($result['success'] ?? false) !== true) {
            return $result;
        }

        $leadId = $this->extractLeadId((string)($result['response'] ?? ''));
        $contactId = $this->extractContactId((string)($result['response'] ?? ''));
        $result['external_id'] = $leadId;
        $result['external_contact_id'] = $contactId;
        $result['note_success'] = false;
        $result['note_error'] = '';

        $answersText = trim((string)($payload['lead']['answers_text'] ?? ''));
        if ($leadId !== '' && $answersText !== '') {
            $note = $this->sendNote($baseUrl, $leadId, $payload, $this->tokenService->getAccessToken());
            $result['note_success'] = (bool)($note['success'] ?? false);
            $result['note_error'] = (string)($note['error'] ?? '');
        }

        return $result;
    }

    private function hasCompleteSettings(string $baseUrl): bool
    {
        return $baseUrl !== ''
            && $this->tokenService->getAccessToken() !== ''
            && trim(ModuleSettingsService::get('amocrm_client_id')) !== ''
            && trim(ModuleSettingsService::get('amocrm_client_secret')) !== ''
            && trim(ModuleSettingsService::get('amocrm_refresh_token')) !== ''
            && trim(ModuleSettingsService::get('amocrm_redirect_uri')) !== '';
    }

    private function buildRequestPayload(array $payload): array
    {
        $lead = is_array($payload['lead'] ?? null) ? $payload['lead'] : [];
        $quiz = is_array($lead['quiz'] ?? null) ? $lead['quiz'] : [];
        $result = is_array($lead['result'] ?? null) ? $lead['result'] : [];
        $client = is_array($lead['client'] ?? null) ? $lead['client'] : [];
        $page = is_array($lead['page'] ?? null) ? $lead['page'] : [];

        $item = [
            'name' => $this->buildTitle($quiz, $result),
            'custom_fields_values' => $this->buildCustomFields($quiz, $result, $page),
            '_embedded' => [
                'contacts' => [$this->buildContact($client)],
                'tags' => $this->buildTags(),
            ],
        ];

        foreach ([
            'price' => (int)ModuleSettingsService::get('amocrm_lead_price'),
            'pipeline_id' => (int)ModuleSettingsService::get('amocrm_pipeline_id'),
            'status_id' => (int)ModuleSettingsService::get('amocrm_status_id'),
            'responsible_user_id' => (int)ModuleSettingsService::get('amocrm_responsible_user_id'),
        ] as $key => $value) {
            if ($value > 0) {
                $item[$key] = $value;
            }
        }

        return [$item];
    }

    private function buildContact(array $client): array
    {
        $fields = [];
        $phone = trim((string)($client['phone'] ?? ''));
        if ($phone !== '') {
            $fields[] = ['field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']]];
        }
        $email = trim((string)($client['email'] ?? ''));
        if ($email !== '') {
            $fields[] = ['field_code' => 'EMAIL', 'values' => [['value' => $email, 'enum_code' => 'WORK']]];
        }

        $contact = ['first_name' => trim((string)($client['name'] ?? '')) ?: 'Без имени'];
        if ($fields !== []) {
            $contact['custom_fields_values'] = $fields;
        }

        return $contact;
    }

    private function buildCustomFields(array $quiz, array $result, array $page): array
    {
        $fields = [];
        foreach ([
            'Код квиза' => $quiz['code'] ?? '',
            'Результат квиза' => $result['title'] ?? '',
            'URL страницы' => $page['url'] ?? '',
        ] as $name => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $fields[] = ['field_name' => $name, 'values' => [['value' => $value]]];
            }
        }

        return $fields;
    }

    private function buildTags(): array
    {
        $tags = [];
        foreach (preg_split('/,/', ModuleSettingsService::get('amocrm_tags')) ?: [] as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '') {
                $tags[] = ['name' => $tag];
            }
        }

        return $tags !== [] ? $tags : [['name' => 'KK Quiz']];
    }

    private function buildTitle(array $quiz, array $result): string
    {
        $title = 'Заявка с квиза';
        $quizName = trim((string)($quiz['name'] ?? ''));
        $resultTitle = trim((string)($result['title'] ?? ''));
        if ($quizName !== '') {
            $title .= ': ' . $quizName;
        }

        return $resultTitle !== '' ? $title . ' — ' . $resultTitle : $title;
    }

    private function sendNote(string $baseUrl, string $leadId, array $payload, string $token): array
    {
        try {
            $body = Json::encode([['note_type' => 'common', 'params' => ['text' => $this->buildNoteText($payload)]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $httpClient = new HttpClient(['socketTimeout' => 15, 'streamTimeout' => 15]);
            $httpClient->setHeader('Content-Type', 'application/json', true);
            $httpClient->setHeader('Authorization', 'Bearer ' . $token, true);
            $response = (string)$httpClient->post($baseUrl . '/api/v4/leads/' . rawurlencode($leadId) . '/notes', $body);
            $status = (int)$httpClient->getStatus();

            return ['success' => $status >= 200 && $status <= 299, 'error' => $status >= 200 && $status <= 299 ? '' : $this->limit('HTTP_' . $status . ' ' . $response, 1000)];
        } catch (\Throwable $exception) {
            return ['success' => false, 'error' => $this->limit($exception->getMessage(), 1000)];
        }
    }

    private function buildNoteText(array $payload): string
    {
        $lead = is_array($payload['lead'] ?? null) ? $payload['lead'] : [];

        return trim((string)($lead['answers_text'] ?? ''));
    }

    private function postJson(string $url, string $body, string $token, float $startedAt): array
    {
        try {
            $httpClient = new HttpClient(['socketTimeout' => 15, 'streamTimeout' => 15]);
            $httpClient->setHeader('Content-Type', 'application/json', true);
            $httpClient->setHeader('Authorization', 'Bearer ' . $token, true);
            $response = (string)$httpClient->post($url, $body);
            $status = (int)$httpClient->getStatus();
            $success = $status >= 200 && $status <= 299;
            $error = $success ? '' : $this->buildError($status, $response);

            return $this->buildResult($success, false, $status, 'HTTP_' . $status, '', '', $error, $url, $body, $response, $this->getDurationMs($startedAt));
        } catch (\Throwable $exception) {
            return $this->buildResult(false, false, 0, 'ERROR', '', '', $exception->getMessage() ?: 'AMOCRM_SEND_FAILED', $url, $body, '', $this->getDurationMs($startedAt));
        }
    }

    private function buildError(int $status, string $response): string
    {
        $error = 'HTTP_' . $status;
        try {
            $decoded = Json::decode($response);
            if (is_array($decoded)) {
                $detail = trim((string)($decoded['detail'] ?? $decoded['title'] ?? $decoded['error_description'] ?? $decoded['error'] ?? ''));
                if ($detail !== '') {
                    $error .= ': ' . $detail;
                }
            }
        } catch (\Throwable) {
        }

        return $error;
    }

    private function extractLeadId(string $response): string
    {
        $first = $this->getFirstLeadFromResponse($response);

        return (string)($first['id'] ?? $first['lead_id'] ?? '');
    }

    private function extractContactId(string $response): string
    {
        $first = $this->getFirstLeadFromResponse($response);
        $embedded = is_array($first['_embedded'] ?? null) ? $first['_embedded'] : [];
        $contacts = is_array($embedded['contacts'] ?? null) ? $embedded['contacts'] : [];
        $contact = is_array($contacts[0] ?? null) ? $contacts[0] : [];

        return (string)($contact['id'] ?? $first['contact_id'] ?? '');
    }

    private function getFirstLeadFromResponse(string $response): array
    {
        $decoded = $this->decodeResponse($response);
        if (is_array($decoded[0] ?? null)) {
            return $decoded[0];
        }

        $embedded = is_array($decoded['_embedded'] ?? null) ? $decoded['_embedded'] : [];
        $leads = is_array($embedded['leads'] ?? null) ? $embedded['leads'] : [];

        return is_array($leads[0] ?? null) ? $leads[0] : [];
    }

    private function decodeResponse(string $response): array
    {
        try {
            $decoded = Json::decode($response);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function buildResult(bool $success, bool $skipped, int $status, string $statusLabel, string $externalId, string $externalContactId, string $error, string $requestUrl, string $requestBody, string $response, int $durationMs, array $extra = []): array
    {
        return array_merge([
            'success' => $success,
            'skipped' => $skipped,
            'status' => $status,
            'status_label' => $statusLabel,
            'external_id' => $externalId,
            'external_contact_id' => $externalContactId,
            'response' => $this->limit($response, 1000),
            'error' => $this->limit($error, 1000),
            'request_url' => $requestUrl,
            'request_body' => $requestBody,
            'duration_ms' => $durationMs,
        ], $extra);
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
