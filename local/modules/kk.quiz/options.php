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
    'webhook_enabled',
    'yandex_metrika_enabled',
    'google_analytics_enabled',
    'save_answers_data',
    'rate_limit_enabled',
    'honeypot_enabled',
    'default_require_agreement',
    'debug_enabled',
    'log_notification_errors',
];
$numericOptions = ['rate_limit_ttl', 'rate_limit_max', 'bitrix24_assigned_by_id', 'telegram_message_thread_id', 'analytics_retention_days', 'webhook_timeout'];
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

    if ($name === 'analytics_retention_days') {
        $allowed = [0, 90, 180, 365];

        return in_array($value, $allowed, true) ? (string)$value : '365';
    }

    if ($name === 'webhook_timeout') {
        $allowed = [3, 5, 10, 15];

        return in_array($value, $allowed, true) ? (string)$value : '5';
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
                if ($name === 'bitrix24_webhook_url') {
                    $settings[$name] = preg_match('#^https?://#i', $postedValue) === 1 ? $postedValue : '';
                } else {
                    $settings[$name] = $postedValue;
                }
            }

            continue;
        }

        $postedValue = $_POST[$name] ?? $defaultValue;
        if ($name === 'webhook_url') {
            $url = $sanitizeText($postedValue);
            $settings[$name] = ($url === '' || preg_match('#^https?://#i', $url) === 1) ? $url : '';
            continue;
        }

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
    $action = (string)($_POST['action'] ?? '');
    $isSaveAction = $action === 'save' || isset($_POST['save']);
    $isRestoreDefaultsAction = $action === 'restore_defaults';
    $isTelegramTestAction = $action === 'telegram_test';

    if ($isRestoreDefaultsAction) {
        ModuleSettingsService::restoreDefaults();
        $message = ['TYPE' => 'OK', 'MESSAGE' => 'Настройки сброшены по умолчанию'];
    } elseif ($isSaveAction || $isTelegramTestAction) {
        $settings = $buildSettingsFromPost();
        $saveSettings($settings);

        if ($isTelegramTestAction) {
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
    $inputId = $name;

    echo '<tr><td width="40%"><label for="' . $e($inputId) . '">' . $e($label) . '</label></td><td width="60%">';
    echo '<input type="password" size="45" autocomplete="new-password" id="' . $e($inputId) . '" name="' . $e($name) . '" value="" data-kk-secret-input>';
    echo ' <button type="button" class="adm-btn kk-quiz-secret-toggle" data-kk-secret-toggle="' . $e($inputId) . '">Показать</button>';
    if ($hasValue) {
        echo '<br><small>Значение уже сохранено. Оставьте поле пустым, чтобы не менять.</small>';
    }
    echo '<br><label><input type="checkbox" name="' . $e($name) . '_clear" value="Y"> Очистить</label>';
    echo '</td></tr>';
};

$renderSelect = static function (string $name, string $label, array $items, string $note = '') use (&$values, $e): void {
    echo '<tr><td width="40%"><label for="' . $e($name) . '">' . $e($label) . '</label></td><td width="60%">';
    echo '<select id="' . $e($name) . '" name="' . $e($name) . '">';
    foreach ($items as $value => $title) {
        $selected = (string)($values[$name] ?? '') === (string)$value ? ' selected' : '';
        echo '<option value="' . $e($value) . '"' . $selected . '>' . $e($title) . '</option>';
    }
    echo '</select>';
    if ($note !== '') {
        echo '<br><small>' . $e($note) . '</small>';
    }
    echo '</td></tr>';
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
    $renderSecretInput('bitrix24_webhook_url', 'Webhook Bitrix24');
    $renderInput('bitrix24_assigned_by_id', 'ID ответственного', 'number');
    $renderInput('bitrix24_source_id', 'Источник');
    ?>
    <tr>
        <td width="40%"></td>
        <td width="60%">
            <button type="button" class="adm-btn" data-kk-quiz-test-bitrix24>Проверить Bitrix24</button>
            <span style="margin-left:10px;" data-kk-quiz-test-bitrix24-result></span>
        </td>
    </tr>
    <tr class="heading"><td colspan="2">Webhook-интеграция</td></tr>
    <?php
    $renderCheckbox('webhook_enabled', 'Включить webhook');
    $renderInput('webhook_url', 'URL webhook', 'text', 'Разрешены только http:// и https:// URL.');
    $renderSecretInput('webhook_secret', 'Секретный ключ webhook');
    $renderSelect('webhook_timeout', 'Таймаут webhook, сек.', [
        '3' => '3',
        '5' => '5',
        '10' => '10',
        '15' => '15',
    ]);
    ?>
    <tr>
        <td width="40%"></td>
        <td width="60%">
            <button type="button" class="adm-btn" data-kk-quiz-test-webhook>Проверить webhook</button>
            <span style="margin-left:10px;" data-kk-quiz-test-webhook-result></span>
        </td>
    </tr>
    <?php
    $tabControl->BeginNextTab();
    $renderCheckbox('yandex_metrika_enabled', 'Включить Яндекс.Метрику');
    $renderInput('yandex_metrika_counter_id', 'ID счётчика Яндекс.Метрики');
    $renderInput('yandex_metrika_first_answer_goal', 'Цель Метрики: ответил на первый вопрос');
    $renderInput('yandex_metrika_result_goal', 'Цель Метрики: дошёл до финала');
    $renderInput('yandex_metrika_result_cta_click_goal', 'Yandex.Metrika: цель клика по CTA результата');
    $renderInput('yandex_metrika_product_click_goal', 'Yandex.Metrika: цель клика по рекомендации');
    $renderInput('yandex_metrika_goal', 'Цель Метрики: отправил форму');
    $renderSelect('analytics_retention_days', 'Срок хранения событий внутренней аналитики', [
        '90' => '90 дней',
        '180' => '180 дней',
        '365' => '365 дней',
        '0' => 'Не удалять автоматически',
    ], 'События внутренней аналитики используются для воронки, отвалов по вопросам и популярных ответов. UTM, IP и User-Agent здесь не хранятся.');
    $renderCheckbox('google_analytics_enabled', 'Включить Google Analytics');
    $renderInput('google_analytics_measurement_id', 'Google Measurement ID');
    $renderInput('google_analytics_first_answer_event_name', 'GA4 event: ответил на первый вопрос');
    $renderInput('google_analytics_result_event_name', 'GA4 event: дошёл до финала');
    $renderInput('google_analytics_result_cta_click_event_name', 'GA4: событие клика по CTA результата');
    $renderInput('google_analytics_product_click_event_name', 'GA4: событие клика по рекомендации');
    $renderInput('google_analytics_event_name', 'GA4 event: отправил форму');
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
    <input type="submit" class="adm-btn-save" name="save" value="Сохранить">
    <button type="submit" class="adm-btn" name="action" value="restore_defaults" onclick="return confirm('Сбросить настройки по умолчанию?');">Сбросить по умолчанию</button>
    <?php $tabControl->End(); ?>
</form>

<script>
(function () {
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-kk-secret-toggle]');
        if (!button) {
            return;
        }

        var inputId = button.getAttribute('data-kk-secret-toggle');
        if (!inputId) {
            return;
        }

        var input = document.getElementById(inputId);
        if (!input) {
            return;
        }

        if (input.type === 'password') {
            input.type = 'text';
            button.textContent = 'Скрыть';
        } else {
            input.type = 'password';
            button.textContent = 'Показать';
        }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-kk-quiz-test-webhook]');
        if (!button) {
            return;
        }

        var resultNode = document.querySelector('[data-kk-quiz-test-webhook-result]');
        var originalText = button.textContent;
        var sessid = window.BX && typeof BX.bitrix_sessid === 'function' ? BX.bitrix_sessid() : '';
        var params = new URLSearchParams({ action: 'kk:quiz.api.testWebhook' });
        if (sessid !== '') {
            params.set('sessid', sessid);
        }

        button.disabled = true;
        button.textContent = 'Отправка...';
        if (resultNode) {
            resultNode.textContent = '';
        }

        fetch('/bitrix/services/main/ajax.php?' + params.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: '{}'
        })
            .then(function (response) { return response.json(); })
            .then(function (response) {
                var data = response && response.data ? response.data : response;
                var message = 'Ошибка отправки webhook';
                if (data && data.skipped && data.reason === 'WEBHOOK_DISABLED') {
                    message = 'Webhook выключен.';
                } else if (data && data.error === 'WEBHOOK_URL_EMPTY') {
                    message = 'URL webhook не задан.';
                } else if (data && data.success === true) {
                    message = 'Успешно отправлено. HTTP ' + String(data.status || 200) + '.';
                } else if (data && data.error) {
                    message = 'Ошибка отправки: ' + data.error;
                }
                if (resultNode) {
                    resultNode.textContent = message;
                } else {
                    alert(message);
                }
            })
            .catch(function () {
                if (resultNode) {
                    resultNode.textContent = 'Ошибка отправки webhook';
                }
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = originalText;
            });
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-kk-quiz-test-bitrix24]');
        if (!button) {
            return;
        }

        var resultNode = document.querySelector('[data-kk-quiz-test-bitrix24-result]');
        var originalText = button.textContent;
        var sessid = window.BX && typeof BX.bitrix_sessid === 'function' ? BX.bitrix_sessid() : '';
        var params = new URLSearchParams({ action: 'kk:quiz.api.testBitrix24' });
        if (sessid !== '') {
            params.set('sessid', sessid);
        }

        button.disabled = true;
        button.textContent = 'Отправка...';
        if (resultNode) {
            resultNode.textContent = '';
        }

        fetch('/bitrix/services/main/ajax.php?' + params.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: '{}'
        })
            .then(function (response) { return response.json(); })
            .then(function (response) {
                var data = response && response.data ? response.data : response;
                var message = 'Ошибка отправки Bitrix24';
                if (data && data.skipped && data.reason === 'BITRIX24_DISABLED') {
                    message = 'Bitrix24 выключен.';
                } else if (data && data.error === 'BITRIX24_WEBHOOK_URL_EMPTY') {
                    message = 'Webhook Bitrix24 не задан.';
                } else if (data && data.success === true) {
                    message = 'Успешно отправлено. ID лида: ' + String(data.external_id || '') + '. HTTP ' + String(data.status || 200) + '.';
                } else if (data && data.error) {
                    message = 'Ошибка отправки: ' + data.error;
                }
                if (resultNode) {
                    resultNode.textContent = message;
                } else {
                    alert(message);
                }
            })
            .catch(function () {
                if (resultNode) {
                    resultNode.textContent = 'Ошибка отправки Bitrix24';
                }
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = originalText;
            });
    });

})();
</script>
