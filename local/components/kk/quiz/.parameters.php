<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arComponentParameters = [
    'PARAMETERS' => [
        'QUIZ_CODE' => [
            'PARENT' => 'BASE',
            'NAME' => 'Код квиза',
            'TYPE' => 'STRING',
            'DEFAULT' => 'pc_selector',
        ],
        'DISPLAY_MODE' => [
            'PARENT' => 'BASE',
            'NAME' => 'Режим отображения',
            'TYPE' => 'LIST',
            'VALUES' => [
                'block' => 'block',
                'popup' => 'popup',
                'button' => 'button',
            ],
            'DEFAULT' => 'block',
        ],
    ],
];
