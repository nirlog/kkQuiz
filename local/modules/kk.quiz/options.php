<?php

use Bitrix\Main\Localization\Loc;

$moduleId = 'kk.quiz';

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

$APPLICATION->SetTitle(Loc::getMessage('KK_QUIZ_OPTIONS_TITLE'));

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<div class="adm-info-message-wrap">
    <div class="adm-info-message">
        <?php echo htmlspecialcharsbx(Loc::getMessage('KK_QUIZ_OPTIONS_STUB')); ?>
    </div>
</div>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
