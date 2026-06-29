<?php

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
        RegisterModule($this->MODULE_ID);
    }

    public function DoUninstall()
    {
        UnRegisterModule($this->MODULE_ID);
    }
}
