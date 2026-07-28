<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

$APPLICATION->SetTitle('KK Quiz — заявки');
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
$iblock = CIBlock::GetList([], ['TYPE' => Installer::IBLOCK_TYPE_ID, 'CODE' => Installer::LEADS_IBLOCK_CODE])->Fetch();
$leadsIblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$tableId = 'kk_quiz_leads_list';
$list = new CAdminList($tableId);
$filterFields = [
    'find_id', 'find_date_from', 'find_date_to', 'find_quiz_code', 'find_result', 'find_status',
    'find_client_name', 'find_client_phone', 'find_client_email', 'find_webhook_status',
    'find_bitrix24_status', 'find_amocrm_status',
];
$list->InitFilter($filterFields);
$filterValue = static fn (string $name): string => trim((string)($GLOBALS[$name] ?? ''));

$statusEnums = [];
if ($leadsIblockId > 0) {
    $statusProperty = CIBlockProperty::GetList([], ['IBLOCK_ID' => $leadsIblockId, 'CODE' => 'KK_LEAD_STATUS'])->Fetch();
    if (is_array($statusProperty)) {
        $enumResult = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => (int)$statusProperty['ID']]);
        while ($enum = $enumResult->Fetch()) {
            $statusEnums[(int)$enum['ID']] = ['value' => (string)$enum['VALUE'], 'xml_id' => (string)$enum['XML_ID']];
        }
    }
}

$queryFilter = ['IBLOCK_ID' => $leadsIblockId];
if (($id = (int)$filterValue('find_id')) > 0) {
    $queryFilter['ID'] = $id;
}
if (($value = $filterValue('find_date_from')) !== '') {
    $queryFilter['>=DATE_CREATE'] = $value . ' 00:00:00';
}
if (($value = $filterValue('find_date_to')) !== '') {
    $queryFilter['<=DATE_CREATE'] = $value . ' 23:59:59';
}
if (($value = $filterValue('find_quiz_code')) !== '') {
    $queryFilter['%PROPERTY_KK_LEAD_QUIZ_CODE'] = $value;
}
if (($value = $filterValue('find_result')) !== '') {
    $queryFilter[] = ['LOGIC' => 'OR', ['%PROPERTY_KK_LEAD_RESULT_TITLE' => $value], ['%PROPERTY_KK_LEAD_RESULT_CODE' => $value]];
}
if (($value = $filterValue('find_status')) !== '') {
    $queryFilter['PROPERTY_KK_LEAD_STATUS'] = $value;
}
foreach ([
    'find_client_name' => 'KK_LEAD_CLIENT_NAME',
    'find_client_phone' => 'KK_LEAD_CLIENT_PHONE',
    'find_client_email' => 'KK_LEAD_CLIENT_EMAIL',
    'find_webhook_status' => 'KK_LEAD_WEBHOOK_STATUS',
    'find_bitrix24_status' => 'KK_LEAD_BITRIX24_STATUS',
    'find_amocrm_status' => 'KK_LEAD_AMOCRM_STATUS',
] as $filterName => $propertyCode) {
    if (($value = $filterValue($filterName)) !== '') {
        $queryFilter['%PROPERTY_' . $propertyCode] = $value;
    }
}

$list->AddHeaders([
    ['id' => 'ID', 'content' => 'ID', 'default' => true],
    ['id' => 'DATE_CREATE', 'content' => 'Дата', 'default' => true],
    ['id' => 'STATUS', 'content' => 'Статус', 'default' => true],
    ['id' => 'CLIENT', 'content' => 'Клиент', 'default' => true],
    ['id' => 'CONTACTS', 'content' => 'Контакты', 'default' => true],
    ['id' => 'QUIZ', 'content' => 'Квиз', 'default' => true],
    ['id' => 'RESULT', 'content' => 'Результат', 'default' => true],
    ['id' => 'PAGE', 'content' => 'Страница', 'default' => true],
    ['id' => 'UTM', 'content' => 'UTM', 'default' => true],
    ['id' => 'EMAIL', 'content' => 'Email', 'default' => true],
    ['id' => 'TELEGRAM', 'content' => 'Telegram', 'default' => true],
    ['id' => 'WEBHOOK', 'content' => 'Webhook', 'default' => true],
    ['id' => 'BITRIX24', 'content' => 'Bitrix24', 'default' => true],
    ['id' => 'AMOCRM', 'content' => 'amoCRM', 'default' => true],
    ['id' => 'MANAGER_NOTE', 'content' => 'Комментарий менеджера', 'default' => true],
]);

$escape = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
$short = static function (mixed $value, int $limit = 60): string {
    $value = trim((string)$value);
    return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
};
$property = static function (array $properties, string $code, bool $xmlId = false): string {
    $property = $properties[$code] ?? [];
    $value = $xmlId ? ($property['VALUE_XML_ID'] ?? $property['VALUE'] ?? '') : ($property['VALUE'] ?? '');
    if (is_array($value)) {
        $value = reset($value);
    }
    return is_scalar($value) ? trim((string)$value) : '';
};
$isDisabledIntegrationValue = static function (string $value): bool {
    $value = mb_strtoupper(trim($value));

    return $value !== '' && (strpos($value, 'DISABLED') !== false
        || strpos($value, '_NOT_CONFIGURED') !== false
        || strpos($value, 'ОТКЛЮЧ') !== false);
};
$integrationStatus = static function (array $properties, string $prefix) use ($property, $escape, $short, $isDisabledIntegrationValue): string {
    $sent = strtoupper($property($properties, $prefix . '_SENT', true));
    $status = $property($properties, $prefix . '_STATUS');
    $error = $property($properties, $prefix . '_ERROR');
    if ($isDisabledIntegrationValue($status) || $isDisabledIntegrationValue($error)) {
        $technicalValue = $isDisabledIntegrationValue($status) ? $status : $error;
        return '<span class="kk-quiz-integration-disabled" title="' . $escape($technicalValue) . '">Отключено</span>';
    }
    if ($error !== '') {
        return '<span style="color:#b42318" title="' . $escape($error) . '">Ошибка: ' . $escape($short($error, 35)) . '</span>';
    }
    if (in_array($sent, ['Y', '1', 'ДА'], true)) {
        return '<span style="color:#287d3c">Отправлено</span>' . ($status !== '' ? '<br>' . $escape($short($status, 35)) : '');
    }
    return $status !== '' ? $escape($short($status, 35)) : '—';
};

if ($leadsIblockId > 0) {
    $elements = CIBlockElement::GetList(['ID' => 'DESC'], $queryFilter, false, ['nPageSize' => 50], ['ID', 'IBLOCK_ID', 'NAME', 'DATE_CREATE']);
    $list->NavText($elements->GetNavPrint('Заявки'));
    while ($element = $elements->GetNextElement()) {
        $fields = $element->GetFields();
        $properties = $element->GetProperties();
        $leadId = (int)$fields['ID'];
        $detailUrl = 'kk_quiz_lead_detail.php?' . http_build_query(['ID' => $leadId, 'lang' => $lang]);
        $editUrl = 'iblock_element_edit.php?' . http_build_query(['IBLOCK_ID' => $leadsIblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'ID' => $leadId, 'lang' => $lang]);
        $quizSectionId = (int)$property($properties, 'KK_LEAD_QUIZ_SECTION_ID');
        $quizCode = $property($properties, 'KK_LEAD_QUIZ_CODE');
        $quizName = $property($properties, 'KK_LEAD_QUIZ_NAME');
        $resultTitle = $property($properties, 'KK_LEAD_RESULT_TITLE');
        $resultCode = $property($properties, 'KK_LEAD_RESULT_CODE');
        $clientName = $property($properties, 'KK_LEAD_CLIENT_NAME');
        $clientPhone = $property($properties, 'KK_LEAD_CLIENT_PHONE');
        $clientEmail = $property($properties, 'KK_LEAD_CLIENT_EMAIL');
        $messenger = $property($properties, 'KK_LEAD_CLIENT_MESSENGER');
        $pageUrl = $property($properties, 'KK_LEAD_PAGE_URL');
        $status = $property($properties, 'KK_LEAD_STATUS');
        $row = &$list->AddRow((string)$leadId, ['ID' => $leadId, 'DATE_CREATE' => $fields['DATE_CREATE']], $detailUrl);
        $row->AddViewField('ID', '<a href="' . $escape($detailUrl) . '">' . $leadId . '</a>');
        $row->AddViewField('STATUS', $escape($status !== '' ? $status : $property($properties, 'KK_LEAD_STATUS', true)));
        $row->AddViewField('CLIENT', '<a href="' . $escape($detailUrl) . '">' . $escape($clientName !== '' ? $clientName : 'Без имени') . '</a>');
        $contacts = array_filter([$clientPhone, $clientEmail, $messenger], static fn (string $item): bool => $item !== '');
        $row->AddViewField('CONTACTS', $contacts !== [] ? implode('<br>', array_map($escape, $contacts)) : '—');
        $quizHtml = $escape($quizName !== '' ? $quizName : $quizCode);
        if ($quizSectionId > 0) {
            $quizUrl = 'kk_quiz_quiz_edit.php?' . http_build_query(['ID' => $quizSectionId, 'lang' => $lang]);
            $quizHtml = '<a href="' . $escape($quizUrl) . '">' . $quizHtml . '</a>';
        }
        if ($quizCode !== '') {
            $statsUrl = 'kk_quiz_statistics.php?' . http_build_query(['quiz_code' => $quizCode, 'lang' => $lang]);
            $quizHtml .= '<br><a href="' . $escape($statsUrl) . '"><small>' . $escape($quizCode) . '</small></a>';
        }
        $row->AddViewField('QUIZ', $quizHtml !== '' ? $quizHtml : '—');
        $resultHtml = $escape($resultTitle !== '' ? $resultTitle : $resultCode) . ($resultCode !== '' ? '<br><small>' . $escape($resultCode) . '</small>' : '');
        $row->AddViewField('RESULT', $resultHtml !== '' ? $resultHtml : '—');
        $safePageUrl = preg_match('#^https?://#i', $pageUrl) === 1;
        $row->AddViewField('PAGE', $safePageUrl ? '<a href="' . $escape($pageUrl) . '" target="_blank" rel="noopener noreferrer" title="' . $escape($pageUrl) . '">' . $escape($short($pageUrl, 45)) . '</a>' : ($pageUrl !== '' ? $escape($short($pageUrl, 45)) : '—'));
        $utm = array_filter([$property($properties, 'KK_LEAD_UTM_SOURCE'), $property($properties, 'KK_LEAD_UTM_MEDIUM'), $property($properties, 'KK_LEAD_UTM_CAMPAIGN')], static fn (string $item): bool => $item !== '');
        $row->AddViewField('UTM', $utm !== [] ? implode(' / ', array_map($escape, $utm)) : '—');
        $row->AddViewField('EMAIL', $integrationStatus($properties, 'KK_LEAD_EMAIL'));
        $row->AddViewField('TELEGRAM', $integrationStatus($properties, 'KK_LEAD_TELEGRAM'));
        $row->AddViewField('WEBHOOK', $integrationStatus($properties, 'KK_LEAD_WEBHOOK'));
        $row->AddViewField('BITRIX24', $integrationStatus($properties, 'KK_LEAD_BITRIX24'));
        $row->AddViewField('AMOCRM', $integrationStatus($properties, 'KK_LEAD_AMOCRM'));
        $note = $property($properties, 'KK_LEAD_MANAGER_NOTE');
        $row->AddViewField('MANAGER_NOTE', $note !== '' ? '<span title="' . $escape($note) . '">' . $escape($short($note)) . '</span>' : '—');
        $row->AddActions([
            ['TEXT' => 'Открыть', 'ACTION' => $list->ActionRedirect($detailUrl), 'DEFAULT' => true],
            ['TEXT' => 'Стандартная карточка', 'ACTION' => $list->ActionRedirect($editUrl)],
        ]);
    }
}

$list->CheckListMode();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

$quizzesUrl = 'kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang]);
$statisticsUrl = 'kk_quiz_statistics.php?' . http_build_query(['lang' => $lang]);
$standardListUrl = 'iblock_element_admin.php?' . http_build_query(['IBLOCK_ID' => $leadsIblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'lang' => $lang]);
$settingsUrl = 'settings.php?' . http_build_query(['mid' => 'kk.quiz', 'lang' => $lang]);
$context = new CAdminContextMenu([
    ['TEXT' => 'Квизы', 'LINK' => $quizzesUrl, 'ICON' => 'btn_list'],
    ['TEXT' => 'Статистика', 'LINK' => $statisticsUrl],
    ['TEXT' => 'Стандартный список заявок', 'LINK' => $standardListUrl],
    ['TEXT' => 'Настройки', 'LINK' => $settingsUrl],
]);
$context->Show();

if ($leadsIblockId <= 0) {
    CAdminMessage::ShowMessage('Инфоблок заявок KK Quiz не найден.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

$filter = new CAdminFilter($tableId . '_filter', ['Дата создания', 'Код квиза', 'Результат', 'Статус обработки', 'Имя клиента', 'Телефон', 'Email', 'Webhook статус', 'Bitrix24 статус', 'amoCRM статус']);
$filter->Begin();
?>
<tr><td>ID заявки:</td><td><input type="text" name="find_id" value="<?= $escape($filterValue('find_id')) ?>"></td></tr>
<tr><td>Дата создания:</td><td><?php echo CalendarPeriod('find_date_from', $filterValue('find_date_from'), 'find_date_to', $filterValue('find_date_to'), $tableId . '_filter', 'Y'); ?></td></tr>
<tr><td>Код квиза:</td><td><input type="text" name="find_quiz_code" value="<?= $escape($filterValue('find_quiz_code')) ?>"></td></tr>
<tr><td>Результат:</td><td><input type="text" name="find_result" value="<?= $escape($filterValue('find_result')) ?>"></td></tr>
<tr><td>Статус обработки:</td><td><?php if ($statusEnums !== []): ?><select name="find_status"><option value="">Все</option><?php foreach ($statusEnums as $enumId => $enum): ?><option value="<?= $enumId ?>"<?= $filterValue('find_status') === (string)$enumId ? ' selected' : '' ?>><?= $escape($enum['value']) ?></option><?php endforeach; ?></select><?php else: ?><input type="text" name="find_status" value="<?= $escape($filterValue('find_status')) ?>"><?php endif; ?></td></tr>
<tr><td>Имя клиента:</td><td><input type="text" name="find_client_name" value="<?= $escape($filterValue('find_client_name')) ?>"></td></tr>
<tr><td>Телефон:</td><td><input type="text" name="find_client_phone" value="<?= $escape($filterValue('find_client_phone')) ?>"></td></tr>
<tr><td>Email:</td><td><input type="text" name="find_client_email" value="<?= $escape($filterValue('find_client_email')) ?>"></td></tr>
<tr><td>Webhook статус:</td><td><input type="text" name="find_webhook_status" value="<?= $escape($filterValue('find_webhook_status')) ?>"></td></tr>
<tr><td>Bitrix24 статус:</td><td><input type="text" name="find_bitrix24_status" value="<?= $escape($filterValue('find_bitrix24_status')) ?>"></td></tr>
<tr><td>amoCRM статус:</td><td><input type="text" name="find_amocrm_status" value="<?= $escape($filterValue('find_amocrm_status')) ?>"></td></tr>
<?php
$filter->Buttons(['table_id' => $tableId, 'url' => $APPLICATION->GetCurPage(), 'form' => 'find_form']);
$filter->End();
?>
<div style="margin:12px 0"><button type="button" class="adm-btn adm-btn-save" id="kk-quiz-export-leads">Экспорт CSV</button></div>
<style>.kk-quiz-integration-disabled{color:#777}</style>
<?php $list->DisplayList(); ?>
<script>
document.getElementById('kk-quiz-export-leads')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'Экспорт...';
    const params = new URLSearchParams({action: 'kk:quiz.api.exportLeads'});
    if (window.BX?.bitrix_sessid) params.set('sessid', BX.bitrix_sessid());
    fetch('/bitrix/services/main/ajax.php?' + params.toString(), {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: '{}'})
        .then(response => response.json())
        .then(response => {
            const data = response?.data || response;
            if (!data || data.success !== true || !data.content) throw new Error('EXPORT_LEADS_FAILED');
            const url = URL.createObjectURL(new Blob([data.content], {type: 'text/csv;charset=utf-8'}));
            const link = document.createElement('a');
            link.href = url;
            link.download = data.filename || 'kk-quiz-leads.csv';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        })
        .catch(error => { console.warn('KK Quiz: leads export failed', error); alert('Не удалось экспортировать заявки.'); })
        .finally(() => { button.disabled = false; button.textContent = original; });
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
