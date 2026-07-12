<?php

$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

$paths = [
    $documentRoot . '/local/modules/kk.quiz/admin/statistics.php',
    $documentRoot . '/bitrix/modules/kk.quiz/admin/statistics.php',
];

foreach ($paths as $path) {
    if (is_file($path)) {
        require_once $path;
        return;
    }
}

require_once $documentRoot . '/bitrix/modules/main/include/prolog_admin_before.php';
require_once $documentRoot . '/bitrix/modules/main/include/prolog_admin_after.php';

echo 'KK Quiz statistics page not found.';

require_once $documentRoot . '/bitrix/modules/main/include/epilog_admin.php';
