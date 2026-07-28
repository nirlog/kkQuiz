<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;
use Kk\Quiz\Service\LeadDeliveryLogService;
use Kk\Quiz\Service\ModuleSettingsService;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

$APPLICATION->SetTitle('KK Quiz — заявка');
if (!Loader::includeModule('kk.quiz') || !Loader::includeModule('iblock')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Модуль kk.quiz или iblock не установлен.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}
if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
}

$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$leadId = (int)($_REQUEST['ID'] ?? 0);
$listUrl = 'kk_quiz_leads.php?' . http_build_query(['lang' => $lang]);
$iblock = CIBlock::GetList([], ['TYPE' => Installer::IBLOCK_TYPE_ID, 'CODE' => Installer::LEADS_IBLOCK_CODE])->Fetch();
$leadsIblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$elementObject = null;
if ($leadId > 0 && $leadsIblockId > 0) {
    $elementObject = CIBlockElement::GetList([], [
        'ID' => $leadId,
        'IBLOCK_ID' => $leadsIblockId,
    ], false, false, [
        'ID', 'IBLOCK_ID', 'NAME', 'DATE_CREATE', 'DETAIL_TEXT', 'ACTIVE', 'CREATED_BY',
    ])->GetNextElement();
}

$fields = is_object($elementObject) ? $elementObject->GetFields() : [];
$properties = is_object($elementObject) ? $elementObject->GetProperties() : [];
$escape = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
$property = static function (array $properties, string $code, bool $xmlId = false): string {
    $item = $properties[$code] ?? [];
    $value = $xmlId ? ($item['VALUE_XML_ID'] ?? $item['VALUE'] ?? '') : ($item['VALUE'] ?? '');
    if (is_array($value)) {
        $value = reset($value);
    }

    return is_scalar($value) ? trim((string)$value) : '';
};
$propertyRaw = static fn (array $properties, string $code): mixed => $properties[$code]['VALUE'] ?? null;
$short = static function (mixed $value, int $limit = 120): string {
    $value = trim((string)$value);
    return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
};

$statusEnums = [];
$statusProperty = $leadsIblockId > 0
    ? CIBlockProperty::GetList([], ['IBLOCK_ID' => $leadsIblockId, 'CODE' => 'KK_LEAD_STATUS'])->Fetch()
    : false;
if (is_array($statusProperty)) {
    $enumResult = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['PROPERTY_ID' => (int)$statusProperty['ID']]);
    while ($enum = $enumResult->Fetch()) {
        $statusEnums[(int)$enum['ID']] = [
            'value' => (string)$enum['VALUE'],
            'xml_id' => (string)$enum['XML_ID'],
        ];
    }
}

$currentStatus = $property($properties, 'KK_LEAD_STATUS');
$currentStatusXml = $property($properties, 'KK_LEAD_STATUS', true);
$currentStatusRawId = $properties['KK_LEAD_STATUS']['VALUE_ENUM_ID'] ?? 0;
if (is_array($currentStatusRawId)) {
    $currentStatusRawId = reset($currentStatusRawId);
}
$currentStatusId = is_scalar($currentStatusRawId) ? (int)$currentStatusRawId : 0;
if ($currentStatusId <= 0) {
    foreach ($statusEnums as $enumId => $enum) {
        if ((string)$enumId === $currentStatus || $enum['value'] === $currentStatus || $enum['xml_id'] === $currentStatusXml) {
            $currentStatusId = $enumId;
            break;
        }
    }
}

$saveError = '';
if (is_object($elementObject) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save'])) {
    if (!check_bitrix_sessid()) {
        $saveError = 'Сессия истекла. Обновите страницу и повторите сохранение.';
    } else {
        $postedStatus = trim((string)($_POST['KK_LEAD_STATUS'] ?? ''));
        $postedNote = trim((string)($_POST['KK_LEAD_MANAGER_NOTE'] ?? ''));
        if ($statusEnums !== [] && !isset($statusEnums[(int)$postedStatus])) {
            $saveError = 'Выбран неизвестный статус обработки.';
        } else {
            try {
                CIBlockElement::SetPropertyValuesEx($leadId, $leadsIblockId, [
                    'KK_LEAD_STATUS' => $statusEnums !== [] ? (int)$postedStatus : $postedStatus,
                    'KK_LEAD_MANAGER_NOTE' => $postedNote,
                ]);
                LocalRedirect('kk_quiz_lead_detail.php?' . http_build_query([
                    'ID' => $leadId,
                    'lang' => $lang,
                    'saved' => 'Y',
                ]));
            } catch (Throwable $exception) {
                $saveError = 'Не удалось сохранить заявку: ' . $exception->getMessage();
            }
        }
    }
}

$quizSectionId = (int)$property($properties, 'KK_LEAD_QUIZ_SECTION_ID');
$quizCode = $property($properties, 'KK_LEAD_QUIZ_CODE');
$quizName = $property($properties, 'KK_LEAD_QUIZ_NAME');
$resultId = (int)$property($properties, 'KK_LEAD_RESULT_ID');
$resultCode = $property($properties, 'KK_LEAD_RESULT_CODE');
$resultTitle = $property($properties, 'KK_LEAD_RESULT_TITLE');
$standardEditUrl = 'iblock_element_edit.php?' . http_build_query([
    'IBLOCK_ID' => $leadsIblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'ID' => $leadId, 'lang' => $lang,
]);
$standardListUrl = 'iblock_element_admin.php?' . http_build_query([
    'IBLOCK_ID' => $leadsIblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'lang' => $lang,
]);
$quizUrl = $quizSectionId > 0 ? 'kk_quiz_quiz_edit.php?' . http_build_query(['ID' => $quizSectionId, 'lang' => $lang]) : '';
$statisticsUrl = $quizCode !== '' ? 'kk_quiz_statistics.php?' . http_build_query(['quiz_code' => $quizCode, 'lang' => $lang]) : '';
$logs = is_object($elementObject) ? (new LeadDeliveryLogService())->getByLeadId($leadId, 20) : [];

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
$contextItems = [
    ['TEXT' => 'К списку заявок', 'LINK' => $listUrl, 'ICON' => 'btn_list'],
];
if (is_object($elementObject)) {
    $contextItems[] = ['TEXT' => 'Стандартная карточка', 'LINK' => $standardEditUrl];
    $contextItems[] = ['TEXT' => 'Стандартный список', 'LINK' => $standardListUrl];
    if ($quizUrl !== '') {
        $contextItems[] = ['TEXT' => 'Настройки квиза', 'LINK' => $quizUrl];
    }
    if ($statisticsUrl !== '') {
        $contextItems[] = ['TEXT' => 'Статистика квиза', 'LINK' => $statisticsUrl];
    }
}
(new CAdminContextMenu($contextItems))->Show();

if (!is_object($elementObject)) {
    $message = $leadsIblockId <= 0
        ? 'Инфоблок заявок KK Quiz не найден.'
        : ($leadId <= 0 ? 'Укажите корректный ID заявки.' : 'Заявка не найдена.');
    CAdminMessage::ShowMessage($message);
    echo '<p><a class="adm-btn" href="' . $escape($listUrl) . '">К списку заявок</a></p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

if ((string)($_GET['saved'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage(['MESSAGE' => 'Изменения сохранены', 'TYPE' => 'OK']);
}
if ($saveError !== '') {
    CAdminMessage::ShowMessage($saveError);
}

$displayValue = static fn (string $value): string => $value !== '' ? $escape($value) : '—';
$linkValue = static function (string $value) use ($escape): string {
    if ($value === '') {
        return '—';
    }
    if (preg_match('#^https?://#i', $value) === 1) {
        return '<a href="' . $escape($value) . '" target="_blank" rel="noopener noreferrer">' . $escape($value) . '</a>';
    }

    return $escape($value);
};
$isSent = static function (string $value): bool {
    return in_array(strtoupper($value), ['Y', '1', 'ДА', 'YES'], true);
};
$isDisabledIntegrationValue = static function (string $value): bool {
    $value = mb_strtoupper(trim($value));

    return $value !== '' && (strpos($value, 'DISABLED') !== false
        || strpos($value, '_NOT_CONFIGURED') !== false
        || strpos($value, 'ОТКЛЮЧ') !== false);
};
$formatAnswers = static function (string $detailText, mixed $answersData) use ($escape): string {
    if (trim($detailText) !== '') {
        return '<pre class="kk-lead-answers">' . $escape($detailText) . '</pre>';
    }
    if (is_string($answersData)) {
        $trimmed = trim($answersData);
        if ($trimmed === '') {
            return '<p class="kk-lead-empty">Ответы не сохранены</p>';
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return '<pre class="kk-lead-answers">' . $escape($trimmed) . '</pre>';
        }
        $answersData = $decoded;
    }
    if (!is_array($answersData) || $answersData === []) {
        return '<p class="kk-lead-empty">Ответы не сохранены</p>';
    }
    $rows = [];
    foreach ($answersData as $answer) {
        if (!is_array($answer)) {
            continue;
        }
        $question = $answer['question'] ?? $answer['question_title'] ?? $answer['title'] ?? '';
        $value = $answer['answer'] ?? $answer['answer_text'] ?? $answer['value'] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        if ((string)$question !== '' || (string)$value !== '') {
            $rows[] = '<div class="kk-lead-answer"><b>' . $escape($question !== '' ? $question : 'Ответ') . ':</b> ' . $escape($value) . '</div>';
        }
    }
    if ($rows !== []) {
        return implode('', $rows);
    }
    $json = json_encode($answersData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return '<pre class="kk-lead-answers">' . $escape(is_string($json) ? $json : '') . '</pre>';
};
$integration = static function (string $title, string $prefix, array $extra = []) use ($properties, $property, $isSent, $escape, $isDisabledIntegrationValue): string {
    $sent = $isSent($property($properties, $prefix . '_SENT', true));
    $status = $property($properties, $prefix . '_STATUS');
    $error = $property($properties, $prefix . '_ERROR');
    $isDisabled = $isDisabledIntegrationValue($status) || $isDisabledIntegrationValue($error);
    $class = $isDisabled ? 'disabled' : ($error !== '' ? 'error' : ($sent ? 'success' : 'empty'));
    $state = $isDisabled ? 'Отключено' : ($error !== '' ? 'Ошибка' : ($sent ? 'Отправлено' : 'Не отправлено'));
    $html = '<div class="kk-lead-delivery"><h4>' . $escape($title) . '</h4>'
        . '<div class="kk-lead-status--' . $class . '">' . $state . '</div>'
        . '<dl><dt>Дата</dt><dd>' . $escape($property($properties, $prefix . '_SENT_AT') ?: '—') . '</dd>';
    if ($status !== '' && !$isDisabled) {
        $html .= '<dt>Статус</dt><dd>' . $escape($status) . '</dd>';
    }
    foreach ($extra as $label => $code) {
        $value = $property($properties, $code);
        if ($value !== '') {
            $html .= '<dt>' . $escape($label) . '</dt><dd>' . $escape($value) . '</dd>';
        }
    }
    if ($isDisabled) {
        $html .= '<dt>Причина</dt><dd>' . $escape($status !== '' ? $status : $error) . '</dd>';
    } elseif ($error !== '') {
        $html .= '<dt>Ошибка</dt><dd class="kk-lead-status--error">' . $escape($error) . '</dd>';
    }
    return $html . '</dl></div>';
};
$integrationAvailability = [
    'webhook' => ModuleSettingsService::getBool('webhook_enabled'),
    'bitrix24' => ModuleSettingsService::getBool('bitrix24_enabled'),
    'amocrm' => ModuleSettingsService::getBool('amocrm_enabled'),
];
$retryButton = static function (string $label, string $disabledLabel, string $action, bool $enabled) use ($escape): string {
    if (!$enabled) {
        return '<button type="button" class="adm-btn kk-lead-retry" disabled title="Интеграция отключена">'
            . $escape($disabledLabel) . '</button>';
    }

    return '<button type="button" class="adm-btn adm-btn-save kk-lead-retry" data-action="' . $escape($action)
        . '" data-name="' . $escape($label) . '">Повторить ' . $escape($label) . '</button>';
};
$statusLabel = $statusEnums[$currentStatusId]['value'] ?? ($currentStatus !== '' ? $currentStatus : $currentStatusXml);
?>
<style>
.kk-lead-detail{display:grid;gap:14px}.kk-lead-detail__header,.kk-lead-card{background:#fff;border:1px solid #cdd2d7;border-radius:6px;padding:14px;box-sizing:border-box}.kk-lead-detail__header h2,.kk-lead-card h3{margin:0 0 12px}.kk-lead-detail__header-meta{display:flex;gap:18px;flex-wrap:wrap}.kk-lead-detail__actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.kk-lead-detail__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.kk-lead-detail__rows{display:grid;grid-template-columns:170px minmax(0,1fr);gap:7px 10px}.kk-lead-detail__rows b{color:#555}.kk-lead-card--wide{min-width:0}.kk-lead-answers,.kk-lead-log-body{white-space:pre-wrap;overflow-wrap:anywhere;background:#f6f8fa;border:1px solid #e1e5ea;border-radius:4px;padding:10px}.kk-lead-answer{padding:7px 0;border-bottom:1px solid #e8ebef}.kk-lead-deliveries{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:10px}.kk-lead-delivery{background:#f6f8fa;border:1px solid #e1e5ea;border-radius:5px;padding:10px}.kk-lead-delivery h4{margin:0 0 7px}.kk-lead-delivery dl{margin:8px 0 0}.kk-lead-delivery dt{font-weight:bold;margin-top:5px}.kk-lead-delivery dd{margin:1px 0}.kk-lead-status--success{color:#287d3c;font-weight:bold}.kk-lead-status--error{color:#b42318;font-weight:bold}.kk-lead-status--disabled{color:#777;font-weight:bold}.kk-lead-status--empty,.kk-lead-empty{color:#777}.kk-lead-logs{width:100%;border-collapse:collapse}.kk-lead-logs th,.kk-lead-logs td{border:1px solid #d6dce5;padding:7px;vertical-align:top;text-align:left}.kk-lead-logs th{background:#eef2f7}.kk-lead-logs details{max-width:420px}.kk-lead-manage textarea{width:100%;min-height:110px;box-sizing:border-box}.kk-lead-manage select,.kk-lead-manage input[type=text]{max-width:100%;width:320px}
@media(max-width:1300px){.kk-lead-deliveries{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:1100px){.kk-lead-detail__grid,.kk-lead-deliveries{grid-template-columns:1fr}.kk-lead-detail__rows{grid-template-columns:1fr}.kk-lead-logs{display:block;overflow-x:auto}}
</style>
<div class="kk-lead-detail">
    <div class="kk-lead-detail__header">
        <h2>Заявка #<?= $leadId ?></h2>
        <div class="kk-lead-detail__header-meta">
            <span><b>Дата:</b> <?= $displayValue((string)($fields['DATE_CREATE'] ?? '')) ?></span>
            <span><b>Статус:</b> <?= $displayValue($statusLabel) ?></span>
            <span><b>Квиз:</b> <?= $displayValue($quizName !== '' ? $quizName : $quizCode) ?></span>
            <span><b>Результат:</b> <?= $displayValue($resultTitle !== '' ? $resultTitle : $resultCode) ?></span>
        </div>
        <div class="kk-lead-detail__actions">
            <a class="adm-btn" href="<?= $escape($listUrl) ?>">К списку заявок</a>
            <a class="adm-btn" href="<?= $escape($standardEditUrl) ?>">Стандартная карточка</a>
            <?php if ($statisticsUrl !== ''): ?><a class="adm-btn" href="<?= $escape($statisticsUrl) ?>">Статистика квиза</a><?php endif; ?>
            <?php if ($quizUrl !== ''): ?><a class="adm-btn" href="<?= $escape($quizUrl) ?>">Настройки квиза</a><?php endif; ?>
        </div>
    </div>

    <div class="kk-lead-detail__grid">
        <section class="kk-lead-card"><h3>Клиент</h3><div class="kk-lead-detail__rows">
            <b>Имя</b><span><?= $displayValue($property($properties, 'KK_LEAD_CLIENT_NAME')) ?></span>
            <b>Телефон</b><span><?= $displayValue($property($properties, 'KK_LEAD_CLIENT_PHONE')) ?></span>
            <b>Email</b><span><?= $displayValue($property($properties, 'KK_LEAD_CLIENT_EMAIL')) ?></span>
            <b>Мессенджер</b><span><?= $displayValue($property($properties, 'KK_LEAD_CLIENT_MESSENGER')) ?></span>
            <b>Комментарий</b><span><?= nl2br($displayValue($property($properties, 'KK_LEAD_CLIENT_COMMENT'))) ?></span>
        </div></section>
        <section class="kk-lead-card"><h3>Квиз и результат</h3><div class="kk-lead-detail__rows">
            <b>Название квиза</b><span><?= $displayValue($quizName) ?></span><b>Код квиза</b><span><?= $displayValue($quizCode) ?></span>
            <b>ID раздела</b><span><?= $quizSectionId > 0 ? $quizSectionId : '—' ?></span><b>Результат</b><span><?= $displayValue($resultTitle) ?></span>
            <b>Код результата</b><span><?= $displayValue($resultCode) ?></span><b>ID результата</b><span><?= $resultId > 0 ? $resultId : '—' ?></span>
        </div></section>
        <section class="kk-lead-card"><h3>Источник заявки</h3><div class="kk-lead-detail__rows">
            <b>Страница</b><span><?= $linkValue($property($properties, 'KK_LEAD_PAGE_URL')) ?></span><b>Referer</b><span><?= $linkValue($property($properties, 'KK_LEAD_REFERER')) ?></span>
            <?php foreach (['Source'=>'SOURCE','Medium'=>'MEDIUM','Campaign'=>'CAMPAIGN','Content'=>'CONTENT','Term'=>'TERM'] as $label => $suffix): ?><b>UTM <?= $label ?></b><span><?= $displayValue($property($properties, 'KK_LEAD_UTM_' . $suffix)) ?></span><?php endforeach; ?>
            <b>User-Agent</b><span title="<?= $escape($property($properties, 'KK_LEAD_USER_AGENT')) ?>"><?= $displayValue($short($property($properties, 'KK_LEAD_USER_AGENT'))) ?></span>
            <b>IP</b><span><?= $displayValue($property($properties, 'KK_LEAD_IP')) ?></span><b>Session ID</b><span><?= $displayValue($property($properties, 'KK_LEAD_SESSION_ID')) ?></span>
            <b>Согласие</b><span><?= $displayValue($property($properties, 'KK_LEAD_AGREEMENT_ACCEPTED', true)) ?></span><b>Политика</b><span><?= $linkValue($property($properties, 'KK_LEAD_PRIVACY_URL')) ?></span>
        </div></section>
        <section class="kk-lead-card kk-lead-manage"><h3>Управление заявкой</h3>
            <form method="post" action="<?= $escape('kk_quiz_lead_detail.php?' . http_build_query(['ID' => $leadId, 'lang' => $lang])) ?>">
                <?= bitrix_sessid_post() ?>
                <p><label><b>Статус обработки</b><br>
                <?php if ($statusEnums !== []): ?><select name="KK_LEAD_STATUS"><?php foreach ($statusEnums as $enumId => $enum): ?><option value="<?= $enumId ?>"<?= $currentStatusId === $enumId ? ' selected' : '' ?>><?= $escape($enum['value']) ?></option><?php endforeach; ?></select>
                <?php else: ?><input type="text" name="KK_LEAD_STATUS" value="<?= $escape($currentStatus) ?>"><?php endif; ?></label></p>
                <p><label><b>Комментарий менеджера</b><br><textarea name="KK_LEAD_MANAGER_NOTE"><?= $escape($property($properties, 'KK_LEAD_MANAGER_NOTE')) ?></textarea></label></p>
                <button type="submit" name="save" value="Y" class="adm-btn adm-btn-save">Сохранить</button>
            </form>
        </section>
    </div>

    <section class="kk-lead-card kk-lead-card--wide"><h3>Ответы</h3><?= $formatAnswers((string)($fields['DETAIL_TEXT'] ?? ''), $propertyRaw($properties, 'KK_LEAD_ANSWERS_DATA')) ?></section>
    <section class="kk-lead-card kk-lead-card--wide"><h3>Доставка и интеграции</h3><div class="kk-lead-deliveries">
        <?= $integration('Email', 'KK_LEAD_EMAIL') ?>
        <?= $integration('Telegram', 'KK_LEAD_TELEGRAM') ?>
        <?= $integration('Webhook', 'KK_LEAD_WEBHOOK') ?>
        <?= $integration('Bitrix24', 'KK_LEAD_BITRIX24', ['ID лида' => 'KK_LEAD_BITRIX24_LEAD_ID']) ?>
        <?= $integration('amoCRM', 'KK_LEAD_AMOCRM', ['ID сделки' => 'KK_LEAD_AMOCRM_LEAD_ID', 'ID контакта' => 'KK_LEAD_AMOCRM_CONTACT_ID']) ?>
    </div><div class="kk-lead-detail__actions">
        <?= $retryButton('Webhook', 'Webhook отключен', 'kk:quiz.api.retryLeadWebhook', $integrationAvailability['webhook']) ?>
        <?= $retryButton('Bitrix24', 'Bitrix24 отключен', 'kk:quiz.api.retryLeadBitrix24', $integrationAvailability['bitrix24']) ?>
        <?= $retryButton('amoCRM', 'amoCRM отключен', 'kk:quiz.api.retryLeadAmoCrm', $integrationAvailability['amocrm']) ?>
    </div></section>
    <section class="kk-lead-card kk-lead-card--wide"><h3>История доставок</h3>
        <table class="kk-lead-logs"><thead><tr><th>Дата</th><th>Канал</th><th>Успех</th><th>Статус</th><th>Ошибка</th><th>Время, мс</th><th>Запрос</th><th>Ответ</th></tr></thead><tbody>
        <?php if ($logs === []): ?><tr><td colspan="8">История доставок пока пуста.</td></tr><?php else: foreach ($logs as $log):
            $date = $log['DATE_CREATE'] ?? '';
            if (is_object($date) && method_exists($date, 'toString')) { $date = $date->toString(); }
            $success = (string)($log['SUCCESS'] ?? '') === 'Y';
            $logDisabled = $isDisabledIntegrationValue((string)($log['STATUS'] ?? ''))
                || $isDisabledIntegrationValue((string)($log['ERROR'] ?? ''));
            $requestBody = (string)($log['REQUEST_BODY'] ?? '');
            $responseBody = (string)($log['RESPONSE_BODY'] ?? '');
        ?><tr><td><?= $escape($date) ?></td><td><?= $escape($log['CHANNEL'] ?? '') ?></td><td class="kk-lead-status--<?= $logDisabled ? 'disabled' : ($success ? 'success' : 'error') ?>"><?= $logDisabled ? 'Отключено' : ($success ? 'Да' : 'Нет') ?></td><td><?= $displayValue((string)($log['STATUS'] ?? '')) ?></td><td class="<?= $logDisabled ? 'kk-lead-status--disabled' : '' ?>" title="<?= $escape($log['ERROR'] ?? '') ?>"><?= $displayValue($short($log['ERROR'] ?? '')) ?></td><td><?= (int)($log['DURATION_MS'] ?? 0) ?></td>
        <td><?php if ($requestBody !== ''): ?><details><summary>Запрос</summary><pre class="kk-lead-log-body"><?= $escape($requestBody) ?></pre></details><?php else: ?>—<?php endif; ?></td>
        <td><?php if ($responseBody !== ''): ?><details><summary>Ответ</summary><pre class="kk-lead-log-body"><?= $escape($responseBody) ?></pre></details><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; endif; ?>
        </tbody></table>
    </section>
    <div class="kk-lead-detail__actions"><a class="adm-btn" href="<?= $escape($listUrl) ?>">Вернуться к списку заявок</a><a class="adm-btn" href="<?= $escape($standardEditUrl) ?>">Открыть стандартную карточку</a></div>
</div>
<script>
document.querySelectorAll('.kk-lead-retry').forEach((button) => {
    button.addEventListener('click', () => {
        if (button.disabled || !button.dataset.action) {
            return;
        }
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Отправка...';
        const params = new URLSearchParams({action: button.dataset.action || ''});
        if (window.BX?.bitrix_sessid) params.set('sessid', BX.bitrix_sessid());
        fetch('/bitrix/services/main/ajax.php?' + params.toString(), {
            method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({lead_id: <?= $leadId ?>})
        }).then((response) => response.json()).then((response) => {
            const data = response?.data || response;
            if (data?.disabled === true) {
                alert(data.message || data.error || 'Интеграция отключена.');
                return;
            }
            if (!data || data.success !== true) {
                const errors = data?.errors ? data.errors.join(', ') : (data?.error || 'DELIVERY_RETRY_FAILED');
                throw new Error(errors);
            }
            alert((button.dataset.name || 'Интеграция') + ' успешно отправлен.');
            window.location.reload();
        }).catch((error) => alert('Не удалось повторить отправку: ' + (error?.message || 'DELIVERY_RETRY_FAILED')))
          .finally(() => { button.disabled = false; button.textContent = originalText; });
    });
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
