<?php

use Bitrix\Main\Web\Json;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$quiz = is_array($arResult['QUIZ'] ?? null) ? $arResult['QUIZ'] : null;
$displayMode = (string)($arResult['DISPLAY_MODE'] ?? 'block');
$error = (string)($arResult['ERROR'] ?? '');
$title = $quiz !== null ? (string)($quiz['title'] ?? '') : '';
$subtitle = $quiz !== null ? (string)($quiz['subtitle'] ?? '') : '';
$startText = $quiz !== null ? (string)($quiz['start_text'] ?? '') : '';
$buttonText = $quiz !== null && (string)($quiz['button_text'] ?? '') !== '' ? (string)$quiz['button_text'] : 'Начать';
$quizCode = $quiz !== null ? (string)($quiz['code'] ?? '') : '';
$isButtonMode = $displayMode === 'button';
$isPopupMode = $displayMode === 'popup' || $isButtonMode;
$rootDisplayMode = $isPopupMode ? 'popup' : 'block';
?>
<?php if ($isButtonMode && $quizCode !== ''): ?>
    <button class="kk-quiz__button kk-quiz__popup-trigger" type="button" data-kk-quiz-popup="<?= htmlspecialcharsbx($quizCode) ?>"><?= htmlspecialcharsbx($buttonText) ?></button>
<?php endif; ?>
<div
    class="kk-quiz kk-quiz--<?= htmlspecialcharsbx($rootDisplayMode) ?>"
    data-kk-quiz
    data-kk-quiz-sessid="<?= htmlspecialcharsbx(bitrix_sessid()) ?>"
    <?php if ($isPopupMode): ?>data-kk-quiz-popup-root data-kk-quiz-code="<?= htmlspecialcharsbx($quizCode) ?>" hidden<?php endif; ?>
>
    <?php if ($isPopupMode): ?>
        <div class="kk-quiz__popup-card" role="dialog" aria-modal="true" aria-label="<?= htmlspecialcharsbx($title !== '' ? $title : 'Квиз') ?>" tabindex="-1">
            <button class="kk-quiz__popup-close" type="button" data-kk-quiz-popup-close aria-label="Закрыть">×</button>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="kk-quiz__error" data-kk-quiz-error><?= htmlspecialcharsbx($error) ?></div>
    <?php elseif ($quiz !== null): ?>
        <div class="kk-quiz__start" data-kk-quiz-start>
            <?php if ($title !== ''): ?>
                <h2 class="kk-quiz__title"><?= htmlspecialcharsbx($title) ?></h2>
            <?php endif; ?>
            <?php if ($subtitle !== ''): ?>
                <div class="kk-quiz__subtitle"><?= htmlspecialcharsbx($subtitle) ?></div>
            <?php endif; ?>
            <?php if ($startText !== ''): ?>
                <div class="kk-quiz__start-text"><?= htmlspecialcharsbx($startText) ?></div>
            <?php endif; ?>
            <button class="kk-quiz__button" type="button" data-kk-quiz-start-button><?= htmlspecialcharsbx($buttonText) ?></button>
        </div>
        <div class="kk-quiz__question" data-kk-quiz-question hidden></div>
        <div class="kk-quiz__form" data-kk-quiz-form hidden></div>
        <div class="kk-quiz__result" data-kk-quiz-result hidden></div>
        <script type="application/json" data-kk-quiz-data><?= Json::encode($quiz) ?></script>
    <?php endif; ?>
    <?php if ($isPopupMode): ?>
        </div>
    <?php endif; ?>
</div>
