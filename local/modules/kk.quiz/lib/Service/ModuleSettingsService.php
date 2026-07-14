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
        'webhook_secret',
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
            'bitrix24_field_site_lead_id' => '',
            'bitrix24_field_quiz_code' => '',
            'bitrix24_field_quiz_name' => '',
            'bitrix24_field_result_code' => '',
            'bitrix24_field_result_title' => '',
            'bitrix24_field_page_url' => '',
            'bitrix24_field_answers_text' => '',
            'bitrix24_field_utm_source' => '',
            'bitrix24_field_utm_medium' => '',
            'bitrix24_field_utm_campaign' => '',
            'bitrix24_field_utm_content' => '',
            'bitrix24_field_utm_term' => '',

            'webhook_enabled' => 'N',
            'webhook_url' => '',
            'webhook_secret' => '',
            'webhook_timeout' => '5',

            'yandex_metrika_enabled' => 'N',
            'yandex_metrika_counter_id' => '',
            'yandex_metrika_first_answer_goal' => 'kk_quiz_first_answer',
            'yandex_metrika_result_goal' => 'kk_quiz_result_reached',
            'yandex_metrika_result_cta_click_goal' => 'kk_quiz_result_cta_click',
            'yandex_metrika_product_click_goal' => 'kk_quiz_recommendation_click',
            'yandex_metrika_goal' => 'kk_quiz_lead',

            'google_analytics_enabled' => 'N',
            'google_analytics_measurement_id' => '',
            'google_analytics_first_answer_event_name' => 'kk_quiz_first_answer',
            'google_analytics_result_event_name' => 'kk_quiz_result_reached',
            'google_analytics_result_cta_click_event_name' => 'kk_quiz_result_cta_click',
            'google_analytics_product_click_event_name' => 'kk_quiz_recommendation_click',
            'google_analytics_event_name' => 'generate_lead',

            'analytics_retention_days' => '365',

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
