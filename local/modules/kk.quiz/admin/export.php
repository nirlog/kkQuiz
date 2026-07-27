<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;
use Kk\Quiz\Service\QuizExportService;

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

$sectionId = (int)($_GET['ID'] ?? 0);
$iblock = CIBlock::GetList([], [
    'TYPE' => Installer::IBLOCK_TYPE_ID,
    'CODE' => Installer::QUIZZES_IBLOCK_CODE,
])->Fetch();
$iblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$section = $sectionId > 0 && $iblockId > 0
    ? CIBlockSection::GetList([], [
        'ID' => $sectionId,
        'IBLOCK_ID' => $iblockId,
    ], false, ['ID', 'CODE', 'NAME'])->Fetch()
    : false;

if (!is_array($section) || trim((string)$section['CODE']) === '') {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Квиз не найден.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

$quizCode = (string)$section['CODE'];
try {
    $export = (new QuizExportService())->exportByCode($quizCode);
} catch (Throwable) {
    $export = null;
}

if ($export === null) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Не удалось экспортировать квиз.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

$fileCode = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $quizCode);
$fileName = 'kk_quiz_' . $fileCode . '_' . date('Ymd_His') . '.json';
$json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (!is_string($json)) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Не удалось подготовить JSON-файл.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

echo $json;
exit;
