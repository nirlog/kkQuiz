<?php

use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loader::registerAutoLoadClasses(
    'kk.quiz',
    [
        'Kk\\Quiz\\Iblock\\Property\\QuizAnswersProperty' => 'lib/Iblock/Property/QuizAnswersProperty.php',
    ]
);
