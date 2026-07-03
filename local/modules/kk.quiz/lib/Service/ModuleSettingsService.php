<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Config\Option;

final class ModuleSettingsService
{
    public const MODULE_ID = 'kk.quiz';

    public const SECRET_OPTIONS = [
        'telegram_bot_token',
        'telegram_proxy_url',
        'bitrix24_webhook_url',
    ];

    public static function getDefaults(): array
    {
        return [
            'email_enabled' => 'Y',
            'email_to' => '',
            'email_admin_link_enabled' => 'Y',

            'telegram_enabled' => 'N',
            'telegram_bot_token' => '',
            'telegram_chat_id' => '',
            'telegram_message_thread_id' => '',
            'telegram_proxy_url' => '',

            'bitrix24_enabled' => 'N',
            'bitrix24_webhook_url' => '',
            'bitrix24_assigned_by_id' => '',
            'bitrix24_source_id' => 'WEB',

            'yandex_metrika_enabled' => 'N',
            'yandex_metrika_counter_id' => '',
            'yandex_metrika_goal' => 'kk_quiz_lead',

            'google_analytics_enabled' => 'N',
            'google_analytics_measurement_id' => '',
            'google_analytics_event_name' => 'generate_lead',

            'default_lead_status' => 'new',
            'save_answers_data' => 'Y',

            'rate_limit_enabled' => 'Y',
            'rate_limit_ttl' => '60',
            'rate_limit_max' => '3',
            'honeypot_enabled' => 'Y',

            'default_privacy_text' => 'Я согласен с политикой обработки персональных данных',
            'default_privacy_url' => '',
            'default_require_agreement' => 'N',

            'debug_enabled' => 'N',
            'log_notification_errors' => 'N',
        ];
    }

    public static function get(string $name): string
    {
        $defaults = self::getDefaults();

        return (string)Option::get(self::MODULE_ID, $name, $defaults[$name] ?? '');
    }

    public static function getBool(string $name): bool
    {
        return self::get($name) === 'Y';
    }

    public static function set(string $name, mixed $value): void
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        Option::set(self::MODULE_ID, $name, $value);
    }

    public static function getAll(): array
    {
        $values = [];
        foreach (array_keys(self::getDefaults()) as $name) {
            $values[$name] = self::get($name);
        }

        return $values;
    }

    public static function restoreDefaults(): void
    {
        Option::delete(self::MODULE_ID);
    }

    public static function isSecretOption(string $name): bool
    {
        return in_array($name, self::SECRET_OPTIONS, true);
    }
}
