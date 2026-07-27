<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

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
$sectionId = (int)($_REQUEST['ID'] ?? 0);
$isCreateMode = (string)($_REQUEST['create'] ?? '') === 'Y' || $sectionId <= 0;
$APPLICATION->SetTitle($isCreateMode ? 'KK Quiz — создание квиза' : 'KK Quiz — настройки квиза');
$iblock = CIBlock::GetList([], ['TYPE' => Installer::IBLOCK_TYPE_ID, 'CODE' => Installer::QUIZZES_IBLOCK_CODE])->Fetch();
$iblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$entityId = 'IBLOCK_' . $iblockId . '_SECTION';
$section = false;
if ($isCreateMode && $iblockId > 0) {
    $section = [
        'ID' => 0,
        'NAME' => '',
        'CODE' => '',
        'ACTIVE' => 'Y',
        'SORT' => 500,
        'UF_KK_TITLE' => '',
        'UF_KK_SUBTITLE' => '',
        'UF_KK_BUTTON_TEXT' => 'Начать',
        'UF_KK_START_TEXT' => '',
        'UF_KK_START_QUESTION' => 0,
        'UF_KK_PROGRESS_TOTAL' => 0,
        'UF_KK_SUCCESS_TEXT' => 'Спасибо! Заявка отправлена.',
    ];
} elseif ($sectionId > 0 && $iblockId > 0) {
    $section = CIBlockSection::GetList([], ['ID' => $sectionId, 'IBLOCK_ID' => $iblockId], false, ['*', 'UF_*'])->Fetch();
}

$listUrl = 'kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang]);
if ((string)($_POST['cancel'] ?? '') !== '' && check_bitrix_sessid()) {
    LocalRedirect($listUrl);
}

$sanitizeString = static fn (mixed $value): string => trim((string)$value);
$sanitizeText = static fn (mixed $value): string => trim((string)$value);
$sanitizeInt = static fn (mixed $value): int => max(0, (int)$value);
$sanitizeBool = static fn (mixed $value): string => (string)$value === 'Y' ? '1' : '0';
$sanitizeHexColor = static function (mixed $value): string {
    $value = trim((string)$value);
    return $value === '' || preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $value) === 1 ? $value : '';
};
$sanitizeMaxWidth = static function (mixed $value): string {
    $value = trim((string)$value);
    return $value === '' || preg_match('/^\d+(?:px|%)?$/', $value) === 1 ? $value : '';
};
$getUserFieldEnumItems = static function (string $entityId, string $fieldName): array {
    $field = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
    if (!is_array($field)) {
        return [];
    }
    $items = [];
    $result = CUserFieldEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['USER_FIELD_ID' => (int)$field['ID']]);
    while ($item = $result->Fetch()) {
        $items[(int)$item['ID']] = $item;
    }
    return $items;
};
$resolveUserFieldEnumIdByXmlId = static function (array $items, mixed $value): int {
    $value = (string)$value;
    foreach ($items as $id => $item) {
        if ((string)$id === $value || (string)($item['XML_ID'] ?? '') === $value) {
            return (int)$id;
        }
    }
    return 0;
};
$normalizeUserFieldEnumPostValue = static function (mixed $value, array $items, bool $multiple = false) use ($resolveUserFieldEnumIdByXmlId): int|array {
    $values = is_array($value) ? $value : [$value];
    $normalized = [];
    foreach ($values as $postedValue) {
        $id = $resolveUserFieldEnumIdByXmlId($items, $postedValue);
        if ($id > 0) {
            $normalized[] = $id;
        }
    }
    $normalized = array_values(array_unique($normalized));
    return $multiple ? $normalized : (int)($normalized[0] ?? 0);
};

$enumFieldNames = ['UF_KK_FORM_FIELDS', 'UF_KK_REQUIRED_FIELDS', 'UF_KK_THEME', 'UF_KK_CATALOG_IBLOCK_IDS', 'UF_KK_IMAGE_RATIO', 'UF_KK_IMAGE_FIT'];
$enums = [];
foreach ($enumFieldNames as $fieldName) {
    $enums[$fieldName] = $getUserFieldEnumItems($entityId, $fieldName);
}

$questions = [];
if (is_array($section) && !$isCreateMode) {
    $questionResult = CIBlockElement::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], [
        'IBLOCK_ID' => $iblockId,
        'SECTION_ID' => $sectionId,
        'ACTIVE' => 'Y',
        'INCLUDE_SUBSECTIONS' => 'N',
    ], false, false, ['ID', 'IBLOCK_ID', 'NAME']);
    while ($element = $questionResult->GetNextElement()) {
        $fields = $element->GetFields();
        $properties = $element->GetProperties();
        $type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE_XML_ID'] ?? $properties['KK_ENTITY_TYPE']['VALUE'] ?? ''));
        if ($type === 'QUESTION') {
            $questions[(int)$fields['ID']] = (string)($properties['KK_PUBLIC_TITLE']['VALUE'] ?? $fields['NAME']);
        }
    }
}

$error = '';
$success = (string)($_GET['saved'] ?? '') === 'Y';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid();
if ($isPost && is_array($section) && ((string)($_POST['save'] ?? '') !== '' || (string)($_POST['apply'] ?? '') !== '')) {
    $code = $sanitizeString($_POST['CODE'] ?? '');
    $sortRaw = trim((string)($_POST['SORT'] ?? ''));
    $maxWidthRaw = trim((string)($_POST['UF_KK_MAX_WIDTH'] ?? ''));
    $hexFields = ['UF_KK_ACCENT_COLOR', 'UF_KK_ACCENT_HOVER', 'UF_KK_ACTIVE_COLOR', 'UF_KK_PROGRESS_COLOR'];
    $errors = [];
    if ($sanitizeString($_POST['NAME'] ?? '') === '') {
        $errors[] = 'Название квиза обязательно.';
    }
    if ($code === '') {
        $errors[] = 'Код квиза обязателен.';
    } elseif (preg_match('/^[a-zA-Z0-9_-]+$/', $code) !== 1) {
        $errors[] = 'Код квиза может содержать только латинские буквы, цифры, дефис и подчёркивание.';
    }
    if ($sortRaw === '' || preg_match('/^\d+$/', $sortRaw) !== 1) {
        $errors[] = 'Сортировка должна быть числом.';
    }
    if ($maxWidthRaw !== '' && $sanitizeMaxWidth($maxWidthRaw) === '') {
        $errors[] = 'Максимальная ширина должна быть числом либо значением в px или %.';
    }
    foreach ($hexFields as $fieldName) {
        $raw = trim((string)($_POST[$fieldName] ?? ''));
        if ($raw !== '' && $sanitizeHexColor($raw) === '') {
            $errors[] = 'Поле «' . $fieldName . '» должно содержать цвет в формате #RGB или #RRGGBB.';
        }
    }

    if ($errors === []) {
        $fields = [
            'NAME' => $sanitizeString($_POST['NAME'] ?? ''),
            'CODE' => $code,
            'SORT' => (int)$sortRaw,
            'ACTIVE' => isset($_POST['ACTIVE']) ? 'Y' : 'N',
        ];
        $stringFields = [
            'UF_KK_TITLE', 'UF_KK_SUBTITLE', 'UF_KK_BUTTON_TEXT', 'UF_KK_FORM_BUTTON_TEXT', 'UF_KK_FORM_TITLE',
            'UF_KK_FORM_SUBTITLE', 'UF_KK_EMAIL_TO', 'UF_KK_METRIKA_COUNTER_ID', 'UF_KK_METRIKA_FIRST_ANSWER_GOAL',
            'UF_KK_METRIKA_RESULT_GOAL', 'UF_KK_METRIKA_RESULT_CTA_GOAL', 'UF_KK_METRIKA_PRODUCT_CLICK_GOAL',
            'UF_KK_METRIKA_GOAL', 'UF_KK_GA_MEASUREMENT_ID', 'UF_KK_GA_FIRST_ANSWER_EVENT', 'UF_KK_GA_RESULT_EVENT',
            'UF_KK_GA_RESULT_CTA_EVENT', 'UF_KK_GA_PRODUCT_CLICK_EVENT', 'UF_KK_GA_FORM_SUBMIT_EVENT',
            'UF_KK_PRIVACY_URL',
        ];
        foreach ($stringFields as $fieldName) {
            $fields[$fieldName] = $sanitizeString($_POST[$fieldName] ?? '');
        }
        foreach (['UF_KK_START_TEXT', 'UF_KK_SUCCESS_TEXT', 'UF_KK_PRIVACY_TEXT'] as $fieldName) {
            $fields[$fieldName] = $sanitizeText($_POST[$fieldName] ?? '');
        }
        foreach (['UF_KK_PROGRESS_TOTAL', 'UF_KK_CATALOG_IBLOCK_ID', 'UF_KK_BORDER_RADIUS', 'UF_KK_CONTAINER_RADIUS', 'UF_KK_CARD_RADIUS', 'UF_KK_BUTTON_RADIUS', 'UF_KK_INPUT_RADIUS', 'UF_KK_IMAGE_RADIUS'] as $fieldName) {
            $fields[$fieldName] = $sanitizeInt($_POST[$fieldName] ?? 0);
        }
        $startQuestionId = $sanitizeInt($_POST['UF_KK_START_QUESTION'] ?? 0);
        $fields['UF_KK_START_QUESTION'] = isset($questions[$startQuestionId]) ? $startQuestionId : 0;
        foreach (['UF_KK_USE_METRIKA', 'UF_KK_USE_GA', 'UF_KK_USE_CATALOG', 'UF_KK_ALLOW_POPUP_URL', 'UF_KK_REQUIRE_AGREEMENT'] as $fieldName) {
            $fields[$fieldName] = $sanitizeBool($_POST[$fieldName] ?? '');
        }
        $fields['UF_KK_MAX_WIDTH'] = $sanitizeMaxWidth($maxWidthRaw);
        foreach ($hexFields as $fieldName) {
            $fields[$fieldName] = $sanitizeHexColor($_POST[$fieldName] ?? '');
        }
        foreach ($enumFieldNames as $fieldName) {
            $multiple = in_array($fieldName, ['UF_KK_FORM_FIELDS', 'UF_KK_REQUIRED_FIELDS', 'UF_KK_CATALOG_IBLOCK_IDS'], true);
            $fields[$fieldName] = $normalizeUserFieldEnumPostValue($_POST[$fieldName] ?? [], $enums[$fieldName], $multiple);
        }
        $sectionUpdater = new CIBlockSection();
        if ($isCreateMode) {
            $fields['IBLOCK_ID'] = $iblockId;
            $newSectionId = (int)$sectionUpdater->Add($fields);
            if ($newSectionId > 0) {
                $target = (string)($_POST['apply'] ?? '') !== ''
                    ? 'kk_quiz_quiz_edit.php?' . http_build_query(['ID' => $newSectionId, 'lang' => $lang, 'saved' => 'Y'])
                    : 'kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang, 'saved' => 'Y']);
                LocalRedirect($target);
            }
            $errors[] = $sectionUpdater->LAST_ERROR ?: 'Не удалось создать квиз.';
        } else {
            if ($sectionUpdater->Update($sectionId, $fields)) {
                $target = (string)($_POST['apply'] ?? '') !== ''
                    ? 'kk_quiz_quiz_edit.php?' . http_build_query(['ID' => $sectionId, 'lang' => $lang, 'saved' => 'Y'])
                    : 'kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang, 'saved' => 'Y']);
                LocalRedirect($target);
            }
            $errors[] = $sectionUpdater->LAST_ERROR ?: 'Не удалось сохранить настройки квиза.';
        }
    }
    $error = implode('<br>', array_map('htmlspecialcharsbx', $errors));
    foreach (['ACTIVE', 'UF_KK_USE_METRIKA', 'UF_KK_USE_GA', 'UF_KK_USE_CATALOG', 'UF_KK_ALLOW_POPUP_URL', 'UF_KK_REQUIRE_AGREEMENT'] as $checkboxName) {
        $section[$checkboxName] = isset($_POST[$checkboxName]) ? 'Y' : 'N';
    }
    $section = array_merge($section, $_POST);
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

if (!is_array($section)) {
    CAdminMessage::ShowMessage('Раздел квиза не найден.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

if ($error !== '') {
    CAdminMessage::ShowMessage(['MESSAGE' => 'Настройки не сохранены', 'DETAILS' => $error, 'TYPE' => 'ERROR', 'HTML' => true]);
} elseif ($success) {
    CAdminMessage::ShowMessage(['MESSAGE' => 'Настройки квиза сохранены', 'TYPE' => 'OK']);
}

$escape = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
$value = static function (string $name) use (&$section): mixed {
    return $section[$name] ?? '';
};
$textRow = static function (string $name, string $label, bool $textarea = false, string $hint = '') use ($escape, $value): void {
    echo '<tr><td width="40%"><label for="' . $escape($name) . '">' . $escape($label) . ':</label></td><td width="60%">';
    if ($textarea) {
        echo '<textarea id="' . $escape($name) . '" name="' . $escape($name) . '" rows="5" cols="60">' . $escape($value($name)) . '</textarea>';
    } else {
        echo '<input type="text" id="' . $escape($name) . '" name="' . $escape($name) . '" value="' . $escape($value($name)) . '" size="50">';
    }
    if ($hint !== '') {
        echo '<div class="adm-info-message-wrap"><div class="adm-info-message">' . $escape($hint) . '</div></div>';
    }
    echo '</td></tr>';
};
$boolRow = static function (string $name, string $label) use ($escape, $value): void {
    $checked = in_array((string)$value($name), ['1', 'Y', 'true'], true) ? ' checked' : '';
    echo '<tr><td width="40%"><label for="' . $escape($name) . '">' . $escape($label) . ':</label></td><td width="60%"><input type="checkbox" id="' . $escape($name) . '" name="' . $escape($name) . '" value="Y"' . $checked . '></td></tr>';
};
$enumRow = static function (string $name, string $label, bool $multiple = false) use ($escape, $value, $enums, $resolveUserFieldEnumIdByXmlId): void {
    $current = is_array($value($name)) ? $value($name) : [$value($name)];
    $selectedIds = array_filter(array_map(static fn ($item) => $resolveUserFieldEnumIdByXmlId($enums[$name], $item), $current));
    echo '<tr><td width="40%"><label for="' . $escape($name) . '">' . $escape($label) . ':</label></td><td width="60%"><select id="' . $escape($name) . '" name="' . $escape($name) . ($multiple ? '[]" multiple size="6"' : '"') . '>';
    if (!$multiple) {
        echo '<option value="">— не выбрано —</option>';
    }
    foreach ($enums[$name] as $id => $item) {
        echo '<option value="' . (int)$id . '"' . (in_array((int)$id, $selectedIds, true) ? ' selected' : '') . '>' . $escape($item['VALUE'] ?? '') . '</option>';
    }
    echo '</select></td></tr>';
};

$quizCode = (string)$section['CODE'];
$contentUrl = 'iblock_list_admin.php?' . http_build_query([
    'IBLOCK_ID' => $iblockId,
    'type' => Installer::IBLOCK_TYPE_ID,
    'SECTION_ID' => $sectionId,
    'find_section_section' => $sectionId,
    'apply_filter' => 'Y',
    'set_filter' => 'Y',
    'lang' => $lang,
]);
$technicalUrl = 'iblock_section_edit.php?' . http_build_query(['IBLOCK_ID' => $iblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'ID' => $sectionId, 'lang' => $lang]);
$exportUrl = 'kk_quiz_export.php?' . http_build_query(['ID' => $sectionId, 'lang' => $lang]);
$statisticsUrl = 'kk_quiz_statistics.php?' . http_build_query(['quiz_code' => $quizCode, 'lang' => $lang]);
$contextItems = [['TEXT' => 'К списку квизов', 'LINK' => $listUrl, 'ICON' => 'btn_list']];
if (!$isCreateMode) {
    $contextItems[] = ['TEXT' => 'Вопросы и результаты', 'LINK' => $contentUrl];
    $contextItems[] = ['TEXT' => 'Экспорт', 'LINK' => $exportUrl];
    $contextItems[] = ['TEXT' => 'Стандартная форма раздела', 'LINK' => $technicalUrl];
    $contextItems[] = ['TEXT' => 'Статистика', 'LINK' => $statisticsUrl];
}
$context = new CAdminContextMenu($contextItems);
$context->Show();

$tabs = [
    ['DIV' => 'main', 'TAB' => 'Основное', 'TITLE' => 'Основные настройки'],
    ['DIV' => 'form', 'TAB' => 'Форма заявки', 'TITLE' => 'Настройки формы заявки'],
    ['DIV' => 'design', 'TAB' => 'Оформление', 'TITLE' => 'Оформление квиза'],
    ['DIV' => 'images', 'TAB' => 'Изображения ответов', 'TITLE' => 'Изображения ответов'],
    ['DIV' => 'metrika', 'TAB' => 'Яндекс Метрика', 'TITLE' => 'Яндекс Метрика'],
    ['DIV' => 'ga', 'TAB' => 'Google Analytics', 'TITLE' => 'Google Analytics'],
    ['DIV' => 'catalog', 'TAB' => 'Рекомендации', 'TITLE' => 'Рекомендации'],
    ['DIV' => 'popup', 'TAB' => 'Popup и политика', 'TITLE' => 'Popup и политика'],
    ['DIV' => 'embed', 'TAB' => 'Вставка на сайт', 'TITLE' => 'Примеры вставки'],
];
$tabControl = new CAdminTabControl('kk_quiz_settings_tabs', $tabs);
?>
<form method="post" action="<?= $escape('kk_quiz_quiz_edit.php?' . http_build_query(array_filter(['ID' => $sectionId, 'create' => $isCreateMode ? 'Y' : '', 'lang' => $lang]))) ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php $textRow('NAME', 'Название квиза'); ?>
    <?php $textRow('CODE', 'Код квиза'); ?>
    <?php $textRow('SORT', 'Сортировка'); ?>
    <tr><td><label for="ACTIVE">Активность:</label></td><td><input type="checkbox" id="ACTIVE" name="ACTIVE" value="Y"<?= (string)$value('ACTIVE') === 'Y' ? ' checked' : '' ?>></td></tr>
    <?php $textRow('UF_KK_TITLE', 'Заголовок'); $textRow('UF_KK_SUBTITLE', 'Подзаголовок', true); $textRow('UF_KK_BUTTON_TEXT', 'Текст кнопки старта'); $textRow('UF_KK_START_TEXT', 'Стартовый текст', true); ?>
    <tr><td><label for="UF_KK_START_QUESTION">Стартовый вопрос:</label></td><td><select id="UF_KK_START_QUESTION" name="UF_KK_START_QUESTION"><option value="">Автоматически: первый активный вопрос по сортировке</option><?php foreach ($questions as $id => $title): ?><option value="<?= $id ?>"<?= (int)$value('UF_KK_START_QUESTION') === $id ? ' selected' : '' ?>><?= $escape($title) ?></option><?php endforeach; ?></select></td></tr>
    <?php $textRow('UF_KK_PROGRESS_TOTAL', 'Количество шагов в прогрессе'); $textRow('UF_KK_SUCCESS_TEXT', 'Текст успешного завершения', true); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php $textRow('UF_KK_FORM_TITLE', 'Заголовок финальной формы'); $textRow('UF_KK_FORM_SUBTITLE', 'Подзаголовок финальной формы', true); $textRow('UF_KK_FORM_BUTTON_TEXT', 'Текст кнопки финальной формы'); $enumRow('UF_KK_FORM_FIELDS', 'Поля формы', true); $enumRow('UF_KK_REQUIRED_FIELDS', 'Обязательные поля формы', true); $textRow('UF_KK_EMAIL_TO', 'Email получателя'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php $enumRow('UF_KK_THEME', 'Тема оформления'); $textRow('UF_KK_MAX_WIDTH', 'Максимальная ширина квиза', false, 'Допустимы число, px или %, например 920, 920px, 80%.'); $textRow('UF_KK_ACCENT_COLOR', 'Акцентный цвет', false, 'Например #e53935'); $textRow('UF_KK_ACCENT_HOVER', 'Акцентный цвет при наведении', false, 'Например #e53935'); $textRow('UF_KK_ACTIVE_COLOR', 'Цвет активного элемента', false, 'Например #e53935'); $textRow('UF_KK_PROGRESS_COLOR', 'Цвет прогресс-бара', false, 'Например #e53935'); $textRow('UF_KK_BORDER_RADIUS', 'Общий legacy radius'); $textRow('UF_KK_CONTAINER_RADIUS', 'Скругление контейнера'); $textRow('UF_KK_CARD_RADIUS', 'Скругление карточек'); $textRow('UF_KK_BUTTON_RADIUS', 'Скругление кнопок'); $textRow('UF_KK_INPUT_RADIUS', 'Скругление полей формы'); $textRow('UF_KK_IMAGE_RADIUS', 'Скругление изображений'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php $enumRow('UF_KK_IMAGE_RATIO', 'Соотношение сторон изображений'); $enumRow('UF_KK_IMAGE_FIT', 'Режим отображения изображений'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php $boolRow('UF_KK_USE_METRIKA', 'Использовать Метрику'); $textRow('UF_KK_METRIKA_COUNTER_ID', 'ID счётчика Метрики'); $textRow('UF_KK_METRIKA_FIRST_ANSWER_GOAL', 'Цель: первый ответ'); $textRow('UF_KK_METRIKA_RESULT_GOAL', 'Цель: показ результата'); $textRow('UF_KK_METRIKA_RESULT_CTA_GOAL', 'Цель: клик по CTA результата'); $textRow('UF_KK_METRIKA_PRODUCT_CLICK_GOAL', 'Цель: клик по рекомендации'); $textRow('UF_KK_METRIKA_GOAL', 'Цель: отправка формы'); ?>
    <tr><td colspan="2"><div class="adm-info-message">Если имя цели пустое, публичный компонент использует системное значение по умолчанию.</div></td></tr>
    <?php $tabControl->BeginNextTab(); ?>
    <?php $boolRow('UF_KK_USE_GA', 'Использовать Google Analytics'); $textRow('UF_KK_GA_MEASUREMENT_ID', 'Google Measurement ID'); $textRow('UF_KK_GA_FIRST_ANSWER_EVENT', 'GA4 event: первый ответ'); $textRow('UF_KK_GA_RESULT_EVENT', 'GA4 event: показ результата'); $textRow('UF_KK_GA_RESULT_CTA_EVENT', 'GA4 event: клик по CTA результата'); $textRow('UF_KK_GA_PRODUCT_CLICK_EVENT', 'GA4 event: клик по рекомендации'); $textRow('UF_KK_GA_FORM_SUBMIT_EVENT', 'GA4 event: отправка формы'); ?>
    <tr><td colspan="2"><div class="adm-info-message">GA4 включается только если включена настройка и указан Measurement ID.</div></td></tr>
    <?php $tabControl->BeginNextTab(); ?>
    <?php $boolRow('UF_KK_USE_CATALOG', 'Показывать рекомендации'); $enumRow('UF_KK_CATALOG_IBLOCK_IDS', 'Инфоблоки рекомендаций', true); $textRow('UF_KK_CATALOG_IBLOCK_ID', 'ID инфоблока рекомендаций legacy', false, "Legacy-поле. Если заполнены 'Инфоблоки рекомендаций', используется новый список."); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php $boolRow('UF_KK_ALLOW_POPUP_URL', 'Разрешить URL для popup'); $textRow('UF_KK_PRIVACY_TEXT', 'Текст политики', true); $textRow('UF_KK_PRIVACY_URL', 'Ссылка на политику'); $boolRow('UF_KK_REQUIRE_AGREEMENT', 'Требовать согласие'); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <?php if ($isCreateMode): ?>
        <tr><td colspan="2"><div class="adm-info-message">Примеры вставки будут доступны после сохранения квиза.</div></td></tr>
    <?php else: ?>
    <?php
    $examples = [
        'Универсальный loader для popup' => '<?$APPLICATION->IncludeComponent("kk:quiz", ".default", [' . "\n" . '    "DISPLAY_MODE" => "loader"' . "\n" . ']);?>',
        'Блочный вывод' => '<?$APPLICATION->IncludeComponent("kk:quiz", ".default", [' . "\n" . '    "QUIZ_CODE" => "' . $quizCode . '",' . "\n" . '    "DISPLAY_MODE" => "block"' . "\n" . ']);?>',
        'Popup-компонент' => '<?$APPLICATION->IncludeComponent("kk:quiz", ".default", [' . "\n" . '    "QUIZ_CODE" => "' . $quizCode . '",' . "\n" . '    "DISPLAY_MODE" => "popup"' . "\n" . ']);?>',
        'Ссылка открытия popup' => '<a href="#" data-kk-quiz-popup="' . $quizCode . '">Пройти квиз</a>',
        'URL-вариант' => '?kkquiz=' . $quizCode,
    ];
    foreach ($examples as $title => $example): ?>
        <tr><td width="20%"><b><?= $escape($title) ?></b></td><td><pre style="display:inline-block;max-width:75%;white-space:pre-wrap;vertical-align:top"><?= $escape($example) ?></pre> <button type="button" class="adm-btn kk-quiz-copy" data-copy="<?= $escape($example) ?>">Скопировать</button></td></tr>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <input type="submit" name="apply" value="Применить">
    <input type="submit" name="cancel" value="Отменить">
    <?php $tabControl->End(); ?>
</form>
<script>
document.querySelectorAll('.kk-quiz-copy').forEach((button) => {
    button.addEventListener('click', () => {
        const done = () => { button.textContent = 'Скопировано'; setTimeout(() => { button.textContent = 'Скопировать'; }, 1500); };
        const fallback = () => { const area = document.createElement('textarea'); area.value = button.dataset.copy || ''; document.body.appendChild(area); area.select(); document.execCommand('copy'); area.remove(); done(); };
        if (navigator.clipboard?.writeText) navigator.clipboard.writeText(button.dataset.copy || '').then(done).catch(fallback);
        else fallback();
    });
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
