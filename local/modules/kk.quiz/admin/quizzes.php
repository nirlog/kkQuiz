<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

$APPLICATION->SetTitle('KK Quiz — квизы');

if (!Loader::includeModule('kk.quiz') || !Loader::includeModule('iblock')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Модуль kk.quiz или iblock не установлен.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
}

$iblock = CIBlock::GetList([], ['TYPE' => Installer::IBLOCK_TYPE_ID, 'CODE' => Installer::QUIZZES_IBLOCK_CODE])->Fetch();
$iblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$list = new CAdminList('kk_quiz_quizzes_list');
$list->AddHeaders([
    ['id' => 'ID', 'content' => 'ID', 'default' => true],
    ['id' => 'NAME', 'content' => 'Название', 'default' => true],
    ['id' => 'CODE', 'content' => 'Код', 'default' => true],
    ['id' => 'ACTIVE', 'content' => 'Активность', 'default' => true],
    ['id' => 'SORT', 'content' => 'Сортировка', 'default' => true],
    ['id' => 'QUESTIONS', 'content' => 'Количество вопросов', 'default' => true],
    ['id' => 'RESULTS', 'content' => 'Количество результатов', 'default' => true],
]);

if ($iblockId > 0) {
    $counts = [];
    $elements = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId],
        false,
        false,
        ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID']
    );
    while ($element = $elements->GetNextElement()) {
        $fields = $element->GetFields();
        $properties = $element->GetProperties();
        $sectionId = (int)($fields['IBLOCK_SECTION_ID'] ?? 0);
        if ($sectionId <= 0) {
            continue;
        }

        $type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE_XML_ID'] ?? ''));
        if ($type === '') {
            $type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE'] ?? ''));
        }

        if (!in_array($type, ['QUESTION', 'RESULT'], true)) {
            continue;
        }

        $counts[$sectionId][$type] = (int)($counts[$sectionId][$type] ?? 0) + 1;
    }

    $sections = CIBlockSection::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId], false, ['ID', 'NAME', 'CODE', 'ACTIVE', 'SORT']);
    while ($section = $sections->Fetch()) {
        $sectionId = (int)$section['ID'];
        $query = static fn (array $values): string => http_build_query(array_merge($values, ['lang' => $lang]));
        $settingsUrl = 'kk_quiz_quiz_edit.php?' . $query(['ID' => $sectionId]);
        $contentUrl = 'iblock_list_admin.php?' . $query([
            'IBLOCK_ID' => $iblockId,
            'type' => Installer::IBLOCK_TYPE_ID,
            'SECTION_ID' => $sectionId,
            'find_section_section' => $sectionId,
            'apply_filter' => 'Y',
            'set_filter' => 'Y',
        ]);
        $exportUrl = 'kk_quiz_export.php?' . $query(['ID' => $sectionId]);
        $technicalUrl = 'iblock_section_edit.php?' . $query(['IBLOCK_ID' => $iblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'ID' => $sectionId]);
        $statisticsUrl = 'kk_quiz_statistics.php?' . $query(['quiz_code' => (string)$section['CODE']]);
        $row = &$list->AddRow((string)$sectionId, $section, $settingsUrl);
        $row->AddViewField('NAME', '<a href="' . htmlspecialcharsbx($settingsUrl) . '">' . htmlspecialcharsbx((string)$section['NAME']) . '</a>');
        $row->AddViewField('ACTIVE', $section['ACTIVE'] === 'Y' ? 'Да' : 'Нет');
        $row->AddViewField('QUESTIONS', (string)($counts[$sectionId]['QUESTION'] ?? 0));
        $row->AddViewField('RESULTS', (string)($counts[$sectionId]['RESULT'] ?? 0));
        $row->AddActions([
            ['TEXT' => 'Настройки', 'ACTION' => $list->ActionRedirect($settingsUrl), 'DEFAULT' => true],
            ['TEXT' => 'Вопросы и результаты', 'ACTION' => $list->ActionRedirect($contentUrl)],
            ['TEXT' => 'Экспорт', 'ACTION' => $list->ActionRedirect($exportUrl)],
            ['TEXT' => 'Стандартное редактирование раздела', 'ACTION' => $list->ActionRedirect($technicalUrl)],
            ['TEXT' => 'Статистика', 'ACTION' => $list->ActionRedirect($statisticsUrl)],
        ]);
    }
}

$list->CheckListMode();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
if ((string)($_GET['saved'] ?? '') === 'Y') {
    CAdminMessage::ShowMessage(['MESSAGE' => 'Настройки квиза сохранены', 'TYPE' => 'OK']);
}
if ($iblockId <= 0) {
    CAdminMessage::ShowMessage('Инфоблок квизов не найден.');
}
$context = new CAdminContextMenu([
    [
        'TEXT' => 'Создать квиз',
        'LINK' => 'kk_quiz_quiz_edit.php?' . http_build_query(['create' => 'Y', 'lang' => $lang]),
        'ICON' => 'btn_new',
    ],
    [
        'TEXT' => 'Импорт квиза',
        'LINK' => 'kk_quiz_import.php?' . http_build_query(['lang' => $lang]),
        'ICON' => 'btn_upload',
    ],
]);
$context->Show();
$list->DisplayList();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
