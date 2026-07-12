<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Service\QuizStatisticsService;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

$APPLICATION->SetTitle('KK Quiz — статистика');

if (!Loader::includeModule('kk.quiz')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    echo '<div class="adm-info-message-wrap"><div class="adm-info-message">Module kk.quiz is not installed.</div></div>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    echo 'Access denied';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

try {
    $summary = (new QuizStatisticsService())->getSummary();
    $error = '';
} catch (\Throwable $exception) {
    $summary = [
        'totals' => ['all' => 0, 'today' => 0, 'last_7_days' => 0, 'last_30_days' => 0],
        'by_quiz' => [],
        'by_result' => [],
        'recent' => [],
        'leads_admin_url' => '',
        'warnings' => [],
    ];
    $error = $exception->getMessage() !== '' ? $exception->getMessage() : 'STATISTICS_FAILED';
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

$escape = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
$totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
$cards = [
    'Всего заявок' => (int)($totals['all'] ?? 0),
    'Сегодня' => (int)($totals['today'] ?? 0),
    '7 дней' => (int)($totals['last_7_days'] ?? 0),
    '30 дней' => (int)($totals['last_30_days'] ?? 0),
];
?>
<style>
.kk-quiz-stat-cards{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 18px;}
.kk-quiz-stat-card{min-width:160px;padding:14px 16px;border:1px solid #d6d6d6;border-radius:6px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);}
.kk-quiz-stat-card__label{color:#666;font-size:12px;margin-bottom:6px;}
.kk-quiz-stat-card__value{font-size:28px;font-weight:bold;line-height:1;}
.kk-quiz-stat-section{margin-top:22px;}
.kk-quiz-stat-actions{margin:10px 0 16px;}
.kk-quiz-stat-empty{padding:12px;color:#777;background:#fff;border:1px solid #d6d6d6;}
</style>

<?php if ($error !== ''): ?>
    <div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message"><?= $escape($error) ?></div></div>
<?php endif; ?>

<?php foreach ((array)($summary['warnings'] ?? []) as $warning): ?>
    <div class="adm-info-message-wrap"><div class="adm-info-message"><?= $escape($warning) ?></div></div>
<?php endforeach; ?>

<div class="kk-quiz-stat-actions">
    <?php if ((string)($summary['leads_admin_url'] ?? '') !== ''): ?>
        <a class="adm-btn" href="<?= $escape($summary['leads_admin_url']) ?>">Открыть список заявок</a>
    <?php endif; ?>
</div>

<div class="kk-quiz-stat-cards">
    <?php foreach ($cards as $label => $value): ?>
        <div class="kk-quiz-stat-card">
            <div class="kk-quiz-stat-card__label"><?= $escape($label) ?></div>
            <div class="kk-quiz-stat-card__value"><?= $escape($value) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="kk-quiz-stat-section">
    <h2>По квизам</h2>
    <?php if ((array)($summary['by_quiz'] ?? []) === []): ?>
        <div class="kk-quiz-stat-empty">Нет данных.</div>
    <?php else: ?>
        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Квиз</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Код</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Заявок</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Последняя заявка</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Действие</div></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array)$summary['by_quiz'] as $row): ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell"><?= $escape($row['quiz_name'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['quiz_code'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['count'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['last_lead_at'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><a href="<?= $escape($row['admin_url'] ?? '') ?>">Открыть заявки</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="kk-quiz-stat-section">
    <h2>По результатам</h2>
    <?php if ((array)($summary['by_result'] ?? []) === []): ?>
        <div class="kk-quiz-stat-empty">Нет данных.</div>
    <?php else: ?>
        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Квиз</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Результат</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Код результата</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Заявок</div></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array)$summary['by_result'] as $row): ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell"><?= $escape($row['quiz_name'] ?? $row['quiz_code'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['result_title'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['result_code'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['count'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="kk-quiz-stat-section">
    <h2>Последние заявки</h2>
    <?php if ((array)($summary['recent'] ?? []) === []): ?>
        <div class="kk-quiz-stat-empty">Нет данных.</div>
    <?php else: ?>
        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Дата</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Квиз</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Результат</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Имя</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Телефон</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Email</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Страница</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Действие</div></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array)$summary['recent'] as $row): ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell"><?= $escape($row['date'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['quiz_name'] ?? $row['quiz_code'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['result_title'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['client_name'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['client_phone'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['client_email'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['page_url'] ?? '') ?></td>
                    <td class="adm-list-table-cell"><a href="<?= $escape($row['admin_url'] ?? '') ?>">Открыть</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
