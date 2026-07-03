<?php

use Bitrix\Main\Loader;
use Kk\Quiz\Service\ModuleSettingsService;
use Kk\Quiz\Service\TelegramNotificationService;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

global $APPLICATION, $USER;

if (!is_object($USER) || !$USER->IsAdmin()) {
    return;
}

if (!Loader::includeModule('kk.quiz')) {
    CAdminMessage::ShowMessage([
        'TYPE' => 'ERROR',
        'MESSAGE' => 'Модуль kk.quiz не подключен',
    ]);
    return;
}

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$message = null;
$moduleId = ModuleSettingsService::MODULE_ID;
$checkboxOptions = [
    'email_enabled',
    'email_admin_link_enabled',
    'telegram_enabled',
    'bitrix24_enabled',
    'yandex_metrika_enabled',
    'google_analytics_enabled',
    'save_answers_data',
    'rate_limit_enabled',
    'honeypot_enabled',
    'default_require_agreement',
    'debug_enabled',
    'log_notification_errors',
];
$numericOptions = ['rate_limit_ttl', 'rate_limit_max', 'bitrix24_assigned_by_id', 'telegram_message_thread_id'];
$secretOptions = ModuleSettingsService::SECRET_OPTIONS;

$sanitizeText = static function (mixed $value): string {
    return is_scalar($value) ? trim((string)$value) : '';
};

$sanitizeNumber = static function (string $name, mixed $value): string {
    $value = is_numeric($value) ? (int)$value : 0;

    if ($name === 'rate_limit_ttl') {
        return (string)min(3600, max(10, $value));
    }

    if ($name === 'rate_limit_max') {
        return (string)min(100, max(1, $value));
    }

    if ($name === 'bitrix24_assigned_by_id') {
        return (string)max(0, $value);
    }

    if ($name === 'telegram_message_thread_id') {
        return (string)max(0, $value);
    }

    return (string)$value;
};

$buildSettingsFromPost = static function () use ($checkboxOptions, $numericOptions, $secretOptions, $sanitizeText, $sanitizeNumber): array {
    $chatIdValue = $sanitizeText($_POST['telegram_chat_id'] ?? '');
    if (preg_match('/^(-?\d+):(\d+)$/', $chatIdValue, $matches) === 1) {
        $_POST['telegram_chat_id'] = $matches[1];
        $_POST['telegram_message_thread_id'] = $matches[2];
    }

    $defaults = ModuleSettingsService::getDefaults();
    $settings = ModuleSettingsService::getAll();

    foreach ($defaults as $name => $defaultValue) {
        if (in_array($name, $checkboxOptions, true)) {
            $settings[$name] = isset($_POST[$name]) ? 'Y' : 'N';
            continue;
        }

        if (in_array($name, $secretOptions, true)) {
            $clearName = $name . '_clear';
            $postedValue = $sanitizeText($_POST[$name] ?? '');

            if (isset($_POST[$clearName])) {
                $settings[$name] = '';
            } elseif ($postedValue !== '') {
                $settings[$name] = $postedValue;
            }

            continue;
        }

        $postedValue = $_POST[$name] ?? $defaultValue;
        $settings[$name] = in_array($name, $numericOptions, true)
            ? $sanitizeNumber($name, $postedValue)
            : $sanitizeText($postedValue);
    }

    return $settings;
};

$saveSettings = static function (array $settings): void {
    foreach (array_keys(ModuleSettingsService::getDefaults()) as $name) {
        ModuleSettingsService::set($name, $settings[$name] ?? '');
    }
};

if ($requestMethod === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'restore_defaults') {
        ModuleSettingsService::restoreDefaults();
        $message = ['TYPE' => 'OK', 'MESSAGE' => 'Настройки сброшены по умолчанию'];
    } else {
        $settings = $buildSettingsFromPost();
        $saveSettings($settings);

        if ($action === 'telegram_test') {
            $result = (new TelegramNotificationService())->sendTestMessage($settings);
            $message = [
                'TYPE' => $result['success'] ? 'OK' : 'ERROR',
                'MESSAGE' => (string)($result['message'] ?? ''),
            ];
        } else {
            $message = ['TYPE' => 'OK', 'MESSAGE' => 'Настройки сохранены'];
        }
    }
} elseif ($requestMethod === 'POST') {
    $message = ['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка проверки сессии'];
}

$values = ModuleSettingsService::getAll();
$tabs = [
    ['DIV' => 'email', 'TAB' => 'Email', 'TITLE' => 'Email-уведомления'],
    ['DIV' => 'telegram', 'TAB' => 'Telegram', 'TITLE' => 'Telegram-уведомления'],
    ['DIV' => 'crm', 'TAB' => 'CRM', 'TITLE' => 'CRM'],
    ['DIV' => 'analytics', 'TAB' => 'Аналитика', 'TITLE' => 'Аналитика'],
    ['DIV' => 'leads', 'TAB' => 'Заявки и антиспам', 'TITLE' => 'Заявки и антиспам'],
    ['DIV' => 'privacy', 'TAB' => 'Privacy', 'TITLE' => 'Privacy'],
    ['DIV' => 'diagnostics', 'TAB' => 'Диагностика', 'TITLE' => 'Диагностика'],
];
$tabControl = new CAdminTabControl('kk_quiz_settings', $tabs);

$e = static function (mixed $value): string {
    return htmlspecialcharsbx((string)$value);
};

$renderCheckbox = static function (string $name, string $label) use (&$values, $e): void {
    $checked = ($values[$name] ?? '') === 'Y' ? ' checked' : '';
    echo '<tr><td width="40%"><label for="' . $e($name) . '">' . $e($label) . '</label></td><td width="60%">';
    echo '<input type="checkbox" id="' . $e($name) . '" name="' . $e($name) . '" value="Y"' . $checked . '>';
    echo '</td></tr>';
};

$renderInput = static function (string $name, string $label, string $type = 'text', string $note = '') use (&$values, $e): void {
    echo '<tr><td width="40%"><label for="' . $e($name) . '">' . $e($label) . '</label></td><td width="60%">';
    echo '<input type="' . $e($type) . '" size="45" id="' . $e($name) . '" name="' . $e($name) . '" value="' . $e($values[$name] ?? '') . '">';
    if ($note !== '') {
        echo '<br><small>' . $e($note) . '</small>';
    }
    echo '</td></tr>';
};

$renderSecretInput = static function (string $name, string $label) use (&$values, $e): void {
    $hasValue = trim((string)($values[$name] ?? '')) !== '';
    echo '<tr><td width="40%"><label for="' . $e($name) . '">' . $e($label) . '</label></td><td width="60%">';
    echo '<input type="password" size="45" autocomplete="new-password" id="' . $e($name) . '" name="' . $e($name) . '" value="">';
    if ($hasValue) {
        echo '<br><small>Значение уже сохранено. Оставьте поле пустым, чтобы не менять.</small>';
    }
    echo '<br><label><input type="checkbox" name="' . $e($name) . '_clear" value="Y"> Очистить</label>';
    echo '</td></tr>';
};

$renderSelect = static function (string $name, string $label, array $items) use (&$values, $e): void {
    echo '<tr><td width="40%"><label for="' . $e($name) . '">' . $e($label) . '</label></td><td width="60%">';
    echo '<select id="' . $e($name) . '" name="' . $e($name) . '">';
    foreach ($items as $value => $title) {
        $selected = (string)($values[$name] ?? '') === (string)$value ? ' selected' : '';
        echo '<option value="' . $e($value) . '"' . $selected . '>' . $e($title) . '</option>';
    }
    echo '</select></td></tr>';
};

$APPLICATION->SetTitle('Настройки модуля KK Quiz');

if ($message !== null) {
    CAdminMessage::ShowMessage($message);
}
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>&amp;lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="mid" value="<?= $e($moduleId) ?>">
    <input type="hidden" name="lang" value="<?= $e(LANGUAGE_ID) ?>">
    <?php $tabControl->Begin(); ?>

    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $renderCheckbox('email_enabled', 'Включить email-уведомления');
    $renderInput('email_to', 'Email получателя по умолчанию');
    $renderCheckbox('email_admin_link_enabled', 'Добавлять ссылку на заявку в админке');
    ?>

    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $renderCheckbox('telegram_enabled', 'Включить Telegram-уведомления');
    $renderSecretInput('telegram_bot_token', 'Telegram Bot Token');
    $renderInput('telegram_chat_id', 'Telegram Chat ID', 'text', 'Например: 123456789, -1001234567890, @channel_name или -1002799533804:6');
    $renderInput(
        'telegram_message_thread_id',
        'Telegram Topic ID / Message Thread ID',
        'number',
        'Необязательно. Используется для отправки в конкретную тему группы. Например: 6'
    );
    $renderSecretInput('telegram_proxy_url', 'Proxy URL');
    ?>
    <tr>
        <td width="40%"></td>
        <td width="60%">
            <button type="submit" class="adm-btn" name="action" value="telegram_test">Проверить отправку</button>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $renderCheckbox('bitrix24_enabled', 'Включить интеграцию с Bitrix24');
    $renderSecretInput('bitrix24_webhook_url', 'Bitrix24 Webhook URL');
    $renderInput('bitrix24_assigned_by_id', 'ID ответственного', 'number');
    $renderInput('bitrix24_source_id', 'Источник');
    ?>

    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $renderCheckbox('yandex_metrika_enabled', 'Включить Яндекс.Метрику');
    $renderInput('yandex_metrika_counter_id', 'ID счётчика Яндекс.Метрики');
    $renderInput('yandex_metrika_goal', 'Цель Яндекс.Метрики');
    $renderCheckbox('google_analytics_enabled', 'Включить Google Analytics');
    $renderInput('google_analytics_measurement_id', 'Google Measurement ID');
    $renderInput('google_analytics_event_name', 'Название события Google Analytics');
    ?>

    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $renderSelect('default_lead_status', 'Статус заявки по умолчанию', [
        'new' => 'Новая',
        'in_progress' => 'В работе',
        'done' => 'Обработана',
        'spam' => 'Спам / мусор',
    ]);
    $renderCheckbox('save_answers_data', 'Сохранять технические данные ответов');
    $renderCheckbox('rate_limit_enabled', 'Включить rate limit');
    $renderInput('rate_limit_ttl', 'Окно rate limit, секунд', 'number');
    $renderInput('rate_limit_max', 'Максимум отправок за окно', 'number');
    $renderCheckbox('honeypot_enabled', 'Включить honeypot');
    ?>

    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $renderInput('default_privacy_text', 'Текст согласия по умолчанию');
    $renderInput('default_privacy_url', 'URL политики по умолчанию');
    $renderCheckbox('default_require_agreement', 'Требовать согласие по умолчанию');
    ?>

    <?php $tabControl->BeginNextTab(); ?>
    <?php
    $renderCheckbox('debug_enabled', 'Включить debug-режим');
    $renderCheckbox('log_notification_errors', 'Логировать ошибки уведомлений');
    ?>

    <?php $tabControl->Buttons(); ?>
    <button type="submit" class="adm-btn-save" name="action" value="save">Сохранить</button>
    <button type="submit" class="adm-btn" name="action" value="restore_defaults" onclick="return confirm('Сбросить настройки по умолчанию?');">Сбросить по умолчанию</button>
    <?php $tabControl->End(); ?>
</form>
