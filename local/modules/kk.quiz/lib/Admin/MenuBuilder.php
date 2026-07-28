<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

final class MenuBuilder
{
    public static function onBuildGlobalMenu(array &$globalMenu, array &$moduleMenu): void
    {
        if (!self::isAdminAllowed()) {
            return;
        }

        $globalMenu['global_menu_kk_quiz'] = [
            'menu_id' => 'kk_quiz',
            'text' => 'KK Quiz',
            'title' => 'KK Quiz',
            'sort' => 350,
            'items_id' => 'global_menu_kk_quiz',
            'items' => [],
        ];
    }

    private static function isAdminAllowed(): bool
    {
        global $USER;

        return is_object($USER)
            && method_exists($USER, 'IsAuthorized')
            && method_exists($USER, 'IsAdmin')
            && $USER->IsAuthorized()
            && $USER->IsAdmin();
    }
}
