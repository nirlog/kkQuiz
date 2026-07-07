<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$installDemo = (string)($_REQUEST['install_demo'] ?? '') === 'Y';
?>

<p>Модуль KK Quiz установлен.</p>

<?php if ($installDemo): ?>
    <p>Демо-квизы созданы:</p>
    <ul>
        <li>Демо: подбор компьютера</li>
        <li>Демо: подбор клининг-услуги</li>
    </ul>

    <p>Пример подключения демо-квизов:</p>
    <pre>&lt;?php
$APPLICATION-&gt;IncludeComponent(
    'kk:quiz',
    '',
    [
        'QUIZ_CODE' =&gt; 'demo-pc-selection',
    ]
);
?&gt;</pre>

    <pre>&lt;?php
$APPLICATION-&gt;IncludeComponent(
    'kk:quiz',
    '',
    [
        'QUIZ_CODE' =&gt; 'demo-cleaning-services',
    ]
);
?&gt;</pre>
<?php endif; ?>
