<?php

declare(strict_types=1);

global $USER;

if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    return false;
}

return [
    'parent_menu' => 'global_menu_services',
    'section' => 'kk_quiz',
    'sort' => 500,
    'text' => 'KK Quiz',
    'title' => 'KK Quiz',
    'items_id' => 'menu_kk_quiz',
    'items' => [
        [
            'text' => 'Статистика',
            'url' => 'kk_quiz_statistics.php?lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'),
            'title' => 'Статистика квизов',
        ],
    ],
];
