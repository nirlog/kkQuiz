<?php

declare(strict_types=1);

global $USER;

if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    return false;
}

return [
    'parent_menu' => 'global_menu_kk_quiz',
    'section' => 'kk_quiz',
    'sort' => 100,
    'text' => 'KK Quiz',
    'title' => 'KK Quiz',
    'items_id' => 'menu_kk_quiz',
    'items' => [
        [
            'text' => 'Квизы',
            'url' => 'kk_quiz_quizzes.php?lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'),
            'title' => 'Список квизов',
        ],
        [
            'text' => 'Заявки',
            'url' => 'kk_quiz_leads.php?lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'),
            'title' => 'Заявки квизов',
        ],
        [
            'text' => 'Статистика',
            'url' => 'kk_quiz_statistics.php?lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'),
            'title' => 'Статистика квизов',
        ],
        [
            'text' => 'Настройки',
            'url' => 'settings.php?mid=kk.quiz&lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'),
            'title' => 'Настройки модуля KK Quiz',
        ],
        [
            'text' => 'Помощь',
            'url' => 'kk_quiz_help.php?lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'),
            'title' => 'KK Quiz — помощь и инструкция',
        ],
    ],
];
