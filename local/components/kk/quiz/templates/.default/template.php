<?php

use Bitrix\Main\Web\Json;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$quiz = is_array($arResult['QUIZ'] ?? null) ? $arResult['QUIZ'] : null;
$displayMode = (string)($arResult['DISPLAY_MODE'] ?? 'block');
$isLoader = (bool)($arResult['IS_LOADER'] ?? false);
$error = (string)($arResult['ERROR'] ?? '');
$title = $quiz !== null ? (string)($quiz['title'] ?? '') : '';
$subtitle = $quiz !== null ? (string)($quiz['subtitle'] ?? '') : '';
$startText = $quiz !== null ? (string)($quiz['start_text'] ?? '') : '';
$buttonText = $quiz !== null && (string)($quiz['button_text'] ?? '') !== '' ? (string)$quiz['button_text'] : 'Начать';
$quizCode = $quiz !== null ? (string)($quiz['code'] ?? '') : '';
$theme = in_array((string)($quiz['theme'] ?? ''), ['dark', 'light'], true) ? (string)$quiz['theme'] : 'light';
$appearance = is_array($quiz['appearance'] ?? null) ? $quiz['appearance'] : [];
$accentColor = preg_match('/^#[0-9a-f]{6}$/i', (string)($appearance['accent_color'] ?? '')) ? (string)$appearance['accent_color'] : '#2563eb';
$accentHoverColor = preg_match('/^#[0-9a-f]{6}$/i', (string)($appearance['accent_hover_color'] ?? '')) ? (string)$appearance['accent_hover_color'] : '#1d4ed8';
$borderRadius = min(48, max(0, (int)($appearance['border_radius'] ?? 20)));
$imageRatio = in_array((string)($appearance['answer_image_ratio'] ?? ''), ['16:9', '4:3', '1:1', '3:4'], true) ? (string)$appearance['answer_image_ratio'] : '16:9';
$imageFit = in_array((string)($appearance['answer_image_fit'] ?? ''), ['cover', 'contain'], true) ? (string)$appearance['answer_image_fit'] : 'cover';
$isButtonMode = $displayMode === 'button';
$isPopupMode = $displayMode === 'popup' || $isButtonMode;
$rootDisplayMode = $isPopupMode ? 'popup' : 'block';
?>
<?php if ($isLoader): ?>
    <div class="kk-quiz-loader" data-kk-quiz-loader data-kk-quiz-sessid="<?= htmlspecialcharsbx(bitrix_sessid()) ?>"></div>
    <?php return; ?>
<?php endif; ?>
<?php if ($isButtonMode && $quizCode !== ''): ?>
    <button class="kk-quiz__button kk-quiz__popup-trigger" type="button" data-kk-quiz-popup="<?= htmlspecialcharsbx($quizCode) ?>"><?= htmlspecialcharsbx($buttonText) ?></button>
<?php endif; ?>
<div
    class="kk-quiz kk-quiz--<?= htmlspecialcharsbx($rootDisplayMode) ?> kk-quiz--theme-<?= htmlspecialcharsbx($theme) ?>"
    style="--kk-quiz-accent: <?= htmlspecialcharsbx($accentColor) ?>; --kk-quiz-accent-hover: <?= htmlspecialcharsbx($accentHoverColor) ?>; --kk-quiz-radius: <?= $borderRadius ?>px; --kk-quiz-image-ratio: <?= htmlspecialcharsbx(str_replace(':', ' / ', $imageRatio)) ?>; --kk-quiz-image-fit: <?= htmlspecialcharsbx($imageFit) ?>;"
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
