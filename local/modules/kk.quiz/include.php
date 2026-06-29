<?php

use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loader::registerAutoLoadClasses(
    'kk.quiz',
    array(
        'Kk\\Quiz\\Helper\\Module' => 'lib/Helper/Module.php',
    )
);
