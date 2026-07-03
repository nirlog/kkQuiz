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
        'Kk\\Quiz\\Repository\\QuizRepository' => 'lib/Repository/QuizRepository.php',
        'Kk\\Quiz\\Repository\\LeadRepository' => 'lib/Repository/LeadRepository.php',
        'Kk\\Quiz\\Service\\QuizService' => 'lib/Service/QuizService.php',
        'Kk\\Quiz\\Service\\LeadService' => 'lib/Service/LeadService.php',
        'Kk\\Quiz\\Service\\ModuleSettingsService' => 'lib/Service/ModuleSettingsService.php',
        'Kk\\Quiz\\Service\\TelegramNotificationService' => 'lib/Service/TelegramNotificationService.php',
        'Kk\\Quiz\\Controller\\Api' => 'lib/Controller/Api.php',
    ]
);
