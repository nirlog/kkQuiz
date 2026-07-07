<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

global $APPLICATION;
?>

<form action="<?= $APPLICATION->GetCurPage(); ?>" method="post">
    <?= bitrix_sessid_post(); ?>

    <input type="hidden" name="lang" value="<?= htmlspecialcharsbx(LANGUAGE_ID); ?>">
    <input type="hidden" name="id" value="kk.quiz">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="2">

    <p>
        Модуль KK Quiz будет установлен. Можно дополнительно создать демо-квизы, чтобы быстро проверить работу компонента.
    </p>

    <p>
        <label>
            <input type="checkbox" name="install_demo" value="Y">
            Установить демо-данные
        </label>
    </p>

    <input type="submit" value="Установить">
</form>
