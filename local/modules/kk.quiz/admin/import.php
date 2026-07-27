<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;
use Kk\Quiz\Service\QuizImportService;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

$APPLICATION->SetTitle('KK Quiz — импорт квиза');

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
$iblock = CIBlock::GetList([], [
    'TYPE' => Installer::IBLOCK_TYPE_ID,
    'CODE' => Installer::QUIZZES_IBLOCK_CODE,
])->Fetch();
$iblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$result = null;
$error = '';
$postedJson = trim((string)($_POST['IMPORT_JSON'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['import'])) {
    try {
        $json = '';
        if (
            isset($_FILES['IMPORT_FILE'])
            && is_array($_FILES['IMPORT_FILE'])
            && is_uploaded_file((string)($_FILES['IMPORT_FILE']['tmp_name'] ?? ''))
        ) {
            $json = (string)file_get_contents((string)$_FILES['IMPORT_FILE']['tmp_name']);
        } else {
            $json = $postedJson;
        }

        if ($json === '') {
            throw new RuntimeException('Не выбран файл и не вставлен JSON.');
        }

        $data = json_decode($json, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Некорректный JSON: ' . json_last_error_msg());
        }

        $result = (new QuizImportService())->import($data);
    } catch (Throwable $exception) {
        $error = $exception->getMessage() === 'INVALID_IMPORT_FORMAT'
            ? 'Неверный формат файла импорта квиза.'
            : $exception->getMessage();
    }
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

$listUrl = 'kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang]);
$context = new CAdminContextMenu([
    [
        'TEXT' => 'К списку квизов',
        'LINK' => $listUrl,
        'ICON' => 'btn_list',
    ],
]);
$context->Show();

if ($error !== '') {
    CAdminMessage::ShowMessage([
        'MESSAGE' => 'Импорт не выполнен',
        'DETAILS' => htmlspecialcharsbx($error),
        'TYPE' => 'ERROR',
        'HTML' => true,
    ]);
}

if (is_array($result)) {
    CAdminMessage::ShowMessage([
        'MESSAGE' => 'Квиз импортирован',
        'DETAILS' =>
            'Название: ' . htmlspecialcharsbx((string)($result['quiz_name'] ?? '')) . '<br>' .
            'Код: ' . htmlspecialcharsbx((string)($result['quiz_code'] ?? '')) . '<br>' .
            'Вопросов: ' . (int)($result['questions_count'] ?? 0) . '<br>' .
            'Результатов: ' . (int)($result['results_count'] ?? 0),
        'TYPE' => 'OK',
        'HTML' => true,
    ]);

    $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];
    if ($warnings !== []) {
        CAdminMessage::ShowMessage([
            'MESSAGE' => 'Импорт завершён с предупреждениями',
            'DETAILS' => implode('<br>', array_map('htmlspecialcharsbx', $warnings)),
            'TYPE' => 'OK',
            'HTML' => true,
        ]);
    }

    $sectionId = (int)($result['section_id'] ?? 0);
    $editUrl = 'kk_quiz_quiz_edit.php?' . http_build_query(['ID' => $sectionId, 'lang' => $lang]);
    $contentUrl = 'iblock_list_admin.php?' . http_build_query([
        'IBLOCK_ID' => $iblockId,
        'type' => Installer::IBLOCK_TYPE_ID,
        'SECTION_ID' => $sectionId,
        'find_section_section' => $sectionId,
        'apply_filter' => 'Y',
        'set_filter' => 'Y',
        'lang' => $lang,
    ]);
    ?>
    <p>
        <a class="adm-btn adm-btn-save" href="<?= htmlspecialcharsbx($editUrl) ?>">Открыть настройки</a>
        <a class="adm-btn" href="<?= htmlspecialcharsbx($contentUrl) ?>">Вопросы и результаты</a>
        <a class="adm-btn" href="<?= htmlspecialcharsbx($listUrl) ?>">К списку квизов</a>
    </p>
    <?php
}

$tabs = [[
    'DIV' => 'import',
    'TAB' => 'Импорт',
    'TITLE' => 'Импорт квиза из JSON',
]];
$tabControl = new CAdminTabControl('kk_quiz_import_tabs', $tabs);
?>
<form method="post" enctype="multipart/form-data" action="kk_quiz_import.php?lang=<?= htmlspecialcharsbx($lang) ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%">JSON-файл:</td>
        <td width="60%"><input type="file" name="IMPORT_FILE" accept=".json,application/json"></td>
    </tr>
    <tr>
        <td width="40%">Или вставьте JSON:</td>
        <td width="60%"><textarea name="IMPORT_JSON" rows="18" cols="90"><?= htmlspecialcharsbx($postedJson) ?></textarea></td>
    </tr>
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="import" value="Импортировать" class="adm-btn-save">
    <a href="<?= htmlspecialcharsbx($listUrl) ?>" class="adm-btn">Отменить</a>
    <?php $tabControl->End(); ?>
</form>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
