<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

final class AmoCrmTokenService
{
    public function getBaseUrl(): string
    {
        $domain = $this->normalizeDomain(ModuleSettingsService::get('amocrm_base_domain'));

        return $domain !== '' ? 'https://' . $domain : '';
    }

    public function getAccessToken(): string
    {
        return trim(ModuleSettingsService::get('amocrm_access_token'));
    }

    public function isLongLivedTokenMode(): bool
    {
        return ModuleSettingsService::getBool('amocrm_long_lived_token');
    }

    public function refreshAccessToken(): array
    {
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            return $this->buildFailure(0, '');
        }

        $body = [
            'client_id' => ModuleSettingsService::get('amocrm_client_id'),
            'client_secret' => ModuleSettingsService::get('amocrm_client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => ModuleSettingsService::get('amocrm_refresh_token'),
            'redirect_uri' => ModuleSettingsService::get('amocrm_redirect_uri'),
        ];

        foreach ($body as $value) {
            if (trim((string)$value) === '') {
                return $this->buildFailure(0, 'AMOCRM_SETTINGS_INCOMPLETE');
            }
        }

        try {
            $requestBody = Json::encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $httpClient = new HttpClient(['socketTimeout' => 15, 'streamTimeout' => 15]);
            $httpClient->setHeader('Content-Type', 'application/json', true);
            $response = (string)$httpClient->post($baseUrl . '/oauth2/access_token', $requestBody);
            $status = (int)$httpClient->getStatus();

            if ($status < 200 || $status > 299) {
                return $this->buildFailure($status, $response);
            }

            $decoded = Json::decode($response);
            if (!is_array($decoded) || trim((string)($decoded['access_token'] ?? '')) === '') {
                return $this->buildFailure($status, $response);
            }

            ModuleSettingsService::set('amocrm_access_token', (string)$decoded['access_token']);
            if (trim((string)($decoded['refresh_token'] ?? '')) !== '') {
                ModuleSettingsService::set('amocrm_refresh_token', (string)$decoded['refresh_token']);
            }

            return ['success' => true, 'status' => $status, 'response' => $this->limit($response)];
        } catch (\Throwable $exception) {
            return $this->buildFailure(0, $exception->getMessage());
        }
    }

    private function normalizeDomain(mixed $value): string
    {
        $value = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = trim(explode('/', $value, 2)[0]);

        return preg_match('/^[a-z0-9.-]+\.amocrm\.(ru|com)$/i', $value) === 1 ? $value : '';
    }

    private function buildFailure(int $status, string $response): array
    {
        return [
            'success' => false,
            'error' => 'AMOCRM_TOKEN_REFRESH_FAILED',
            'status' => $status,
            'response' => $this->limit($response),
        ];
    }

    private function limit(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';

        return function_exists('mb_substr') ? (string)mb_substr($value, 0, 1000) : substr($value, 0, 1000);
    }
}
