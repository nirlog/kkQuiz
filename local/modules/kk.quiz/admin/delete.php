<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

$APPLICATION->SetTitle('KK Quiz — удаление квиза');

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
$iblock = CIBlock::GetList([], [
    'TYPE' => Installer::IBLOCK_TYPE_ID,
    'CODE' => Installer::QUIZZES_IBLOCK_CODE,
])->Fetch();
$iblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$section = $sectionId > 0 && $iblockId > 0
    ? CIBlockSection::GetList([], [
        'ID' => $sectionId,
        'IBLOCK_ID' => $iblockId,
    ], false, ['ID', 'IBLOCK_ID', 'NAME', 'CODE'])->Fetch()
    : false;
$listUrl = 'kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang]);
$editUrl = 'kk_quiz_quiz_edit.php?' . http_build_query(['ID' => $sectionId, 'lang' => $lang]);

if (!is_array($section)) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Квиз не найден.');
    echo '<p><a class="adm-btn" href="' . htmlspecialcharsbx($listUrl) . '">К списку квизов</a></p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

$counts = ['QUESTION' => 0, 'RESULT' => 0, 'OTHER' => 0];
$elementIds = [];
$elements = CIBlockElement::GetList(
    ['SORT' => 'ASC', 'ID' => 'ASC'],
    [
        'IBLOCK_ID' => $iblockId,
        'SECTION_ID' => $sectionId,
        'INCLUDE_SUBSECTIONS' => 'N',
    ],
    false,
    false,
    ['ID', 'IBLOCK_ID', 'NAME']
);
while ($element = $elements->GetNextElement()) {
    $fields = $element->GetFields();
    $properties = $element->GetProperties();
    $elementId = (int)$fields['ID'];
    if ($elementId > 0) {
        $elementIds[] = $elementId;
    }

    $type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE_XML_ID'] ?? ''));
    if ($type === '') {
        $type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE'] ?? ''));
    }
    if ($type === 'QUESTION') {
        $counts['QUESTION']++;
    } elseif ($type === 'RESULT') {
        $counts['RESULT']++;
    } else {
        $counts['OTHER']++;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['delete'])) {
    try {
        foreach ($elementIds as $elementId) {
            if (!CIBlockElement::Delete($elementId)) {
                throw new RuntimeException('Не удалось удалить элемент ID ' . $elementId . '.');
            }
        }

        if (!CIBlockSection::Delete($sectionId)) {
            $exception = is_object($APPLICATION) ? $APPLICATION->GetException() : null;
            $message = is_object($exception) ? $exception->GetString() : '';
            throw new RuntimeException($message !== '' ? $message : 'Не удалось удалить раздел квиза.');
        }

        LocalRedirect('kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang, 'deleted' => 'Y']));
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

$context = new CAdminContextMenu([
    ['TEXT' => 'К списку квизов', 'LINK' => $listUrl, 'ICON' => 'btn_list'],
    ['TEXT' => 'Настройки квиза', 'LINK' => $editUrl],
]);
$context->Show();

if ($error !== '') {
    CAdminMessage::ShowMessage([
        'MESSAGE' => 'Квиз не удалён',
        'DETAILS' => htmlspecialcharsbx($error),
        'TYPE' => 'ERROR',
        'HTML' => true,
    ]);
}

CAdminMessage::ShowMessage([
    'MESSAGE' => 'Подтвердите удаление квиза',
    'DETAILS' =>
        'Будет удалён квиз: <b>' . htmlspecialcharsbx((string)$section['NAME']) . '</b><br>' .
        'Код: <b>' . htmlspecialcharsbx((string)$section['CODE']) . '</b><br>' .
        'Вопросов: ' . (int)$counts['QUESTION'] . '<br>' .
        'Результатов: ' . (int)$counts['RESULT'] . '<br><br>' .
        'Вопросы и результаты этого квиза будут удалены без возможности восстановления.<br>' .
        'Заявки, статистика и логи интеграций в этом действии не удаляются.',
    'TYPE' => 'ERROR',
    'HTML' => true,
]);
?>
<form method="post" action="kk_quiz_delete.php?ID=<?= (int)$sectionId ?>&amp;lang=<?= htmlspecialcharsbx($lang) ?>">
    <?= bitrix_sessid_post() ?>
    <input type="submit" name="delete" value="Удалить квиз" class="adm-btn adm-btn-save">
    <a href="<?= htmlspecialcharsbx($listUrl) ?>" class="adm-btn">Отменить</a>
</form>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
