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
        'Kk\\Quiz\\Analytics\\QuizEventTable' => 'lib/Analytics/QuizEventTable.php',
        'Kk\\Quiz\\Admin\\ElementFormAssets' => 'lib/Admin/ElementFormAssets.php',
        'Kk\\Quiz\\Admin\\ElementListAssets' => 'lib/Admin/ElementListAssets.php',
        'Kk\\Quiz\\Admin\\LeadListAssets' => 'lib/Admin/LeadListAssets.php',
        'Kk\\Quiz\\Admin\\QuizStructureDiagnostics' => 'lib/Admin/QuizStructureDiagnostics.php',
        'Kk\\Quiz\\Admin\\SectionFormAssets' => 'lib/Admin/SectionFormAssets.php',
        'Kk\\Quiz\\Repository\\QuizRepository' => 'lib/Repository/QuizRepository.php',
        'Kk\\Quiz\\Repository\\LeadRepository' => 'lib/Repository/LeadRepository.php',
        'Kk\\Quiz\\Service\\QuizService' => 'lib/Service/QuizService.php',
        'Kk\\Quiz\\Service\\QuizExportService' => 'lib/Service/QuizExportService.php',
        'Kk\\Quiz\\Service\\QuizImportService' => 'lib/Service/QuizImportService.php',
        'Kk\\Quiz\\Service\\LeadService' => 'lib/Service/LeadService.php',
        'Kk\\Quiz\\Service\\LeadExportService' => 'lib/Service/LeadExportService.php',
        'Kk\\Quiz\\Service\\QuizStatisticsService' => 'lib/Service/QuizStatisticsService.php',
        'Kk\\Quiz\\Service\\QuizFunnelStatisticsService' => 'lib/Service/QuizFunnelStatisticsService.php',
        'Kk\\Quiz\\Service\\QuizEventService' => 'lib/Service/QuizEventService.php',
        'Kk\\Quiz\\Service\\ModuleSettingsService' => 'lib/Service/ModuleSettingsService.php',
        'Kk\\Quiz\\Service\\TelegramNotificationService' => 'lib/Service/TelegramNotificationService.php',
        'Kk\\Quiz\\Controller\\Api' => 'lib/Controller/Api.php',
    ]
);

if (class_exists(\Kk\Quiz\Iblock\Installer::class)) {
    \Kk\Quiz\Iblock\Installer::ensureEventHandlers();
}
