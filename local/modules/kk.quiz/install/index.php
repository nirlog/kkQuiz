<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

class kk_quiz extends CModule
{
    public $MODULE_ID = 'kk.quiz';
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $this->MODULE_NAME = Loc::getMessage('KK_QUIZ_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('KK_QUIZ_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('KK_QUIZ_PARTNER_NAME');
        $this->PARTNER_URI = Loc::getMessage('KK_QUIZ_PARTNER_URI');
    }

    public function DoInstall()
    {
        global $APPLICATION;

        $step = (int)($_REQUEST['step'] ?? 1);

        if ($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('KK_QUIZ_MODULE_INSTALL_TITLE') ?: 'Установка KK Quiz',
                __DIR__ . '/step.php'
            );

            return true;
        }

        RegisterModule($this->MODULE_ID);

        try {
            Loader::includeModule($this->MODULE_ID);
            require_once __DIR__ . '/../lib/Iblock/DemoDataInstaller.php';

            \Kk\Quiz\Iblock\Installer::install();

            if ((string)($_REQUEST['install_demo'] ?? '') === 'Y') {
                \Kk\Quiz\Iblock\DemoDataInstaller::install();
            }
        } catch (\Throwable $exception) {
            UnRegisterModule($this->MODULE_ID);

            if (is_object($APPLICATION)) {
                $APPLICATION->ThrowException($exception->getMessage());
            }

            return false;
        }

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('KK_QUIZ_MODULE_INSTALL_FINISH_TITLE') ?: 'Установка KK Quiz завершена',
            __DIR__ . '/finish.php'
        );

        return true;
    }

    public function DoUninstall()
    {
        if (Loader::includeModule($this->MODULE_ID)) {
            \Kk\Quiz\Iblock\Installer::uninstall();
        }

        UnRegisterModule($this->MODULE_ID);

        return true;
    }
}
