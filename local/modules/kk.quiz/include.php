<?php

use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loader::registerAutoLoadClasses(
    'kk.quiz',
    [
        'Kk\\Quiz\\Helper\\Module' => 'lib/Helper/Module.php',
        'Kk\\Quiz\\Iblock\\Installer' => 'lib/Iblock/Installer.php',
        'Kk\\Quiz\\Iblock\\Property\\QuizAnswersProperty' => 'lib/Iblock/Property/QuizAnswersProperty.php',
        'Kk\\Quiz\\Admin\\ElementFormAssets' => 'lib/Admin/ElementFormAssets.php',
    ]
);
