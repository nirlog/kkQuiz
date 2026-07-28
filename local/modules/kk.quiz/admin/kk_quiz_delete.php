<?php

$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

foreach ([
    $documentRoot . '/local/modules/kk.quiz/admin/delete.php',
    $documentRoot . '/bitrix/modules/kk.quiz/admin/delete.php',
] as $path) {
    if (is_file($path)) {
        require_once $path;
        return;
    }
}

require_once $documentRoot . '/bitrix/modules/main/include/prolog_admin_before.php';
require_once $documentRoot . '/bitrix/modules/main/include/prolog_admin_after.php';
echo 'KK Quiz delete page not found.';
require_once $documentRoot . '/bitrix/modules/main/include/epilog_admin.php';
