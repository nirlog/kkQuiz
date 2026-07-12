<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Service\QuizEventMaintenanceService;
use Kk\Quiz\Service\QuizFunnelStatisticsService;
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

$getRequestValue = static fn (string $key): string => trim((string)($_GET[$key] ?? ''));
$allowedPeriods = ['today', '7d', '30d', 'all', 'custom'];
$period = $getRequestValue('period') ?: '30d';
if (!in_array($period, $allowedPeriods, true)) {
    $period = '30d';
}

$validateDate = static function (string $value): string {
    if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return '';
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));

    return checkdate($month, $day, $year) ? $value : '';
};

$customDateFrom = $validateDate($getRequestValue('date_from'));
$customDateTo = $validateDate($getRequestValue('date_to'));
$dateFrom = null;
$dateTo = null;
$today = date('Y-m-d');

switch ($period) {
    case 'today':
        $dateFrom = $today;
        $dateTo = $today;
        break;
    case '7d':
        $dateFrom = date('Y-m-d', strtotime('-7 days') ?: time());
        $dateTo = $today;
        break;
    case 'all':
        break;
    case 'custom':
        $dateFrom = $customDateFrom !== '' ? $customDateFrom : null;
        $dateTo = $customDateTo !== '' ? $customDateTo : null;
        break;
    case '30d':
    default:
        $period = '30d';
        $dateFrom = date('Y-m-d', strtotime('-30 days') ?: time());
        $dateTo = $today;
        break;
}

try {
    $summary = (new QuizStatisticsService())->getSummary([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);
    $error = '';
} catch (\Throwable $exception) {
    $summary = [
        'totals' => ['all' => 0, 'today' => 0, 'last_7_days' => 0, 'last_30_days' => 0],
        'period' => ['date_from' => '', 'date_to' => '', 'label' => ''],
        'period_total' => 0,
        'by_quiz' => [],
        'by_result' => [],
        'recent' => [],
        'leads_admin_url' => '',
        'warnings' => [],
    ];
    $error = $exception->getMessage() !== '' ? $exception->getMessage() : 'STATISTICS_FAILED';
}

try {
    $funnelSummary = (new QuizFunnelStatisticsService())->getSummary([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);
} catch (\Throwable) {
    $funnelSummary = [
        'summary_cards' => [],
        'insights' => [],
        'funnel_by_quiz' => [],
        'question_dropoff' => [],
        'popular_answers' => [],
        'warnings' => [],
    ];
}

try {
    $orphanQuizCodes = (new QuizEventMaintenanceService())->getOrphanQuizCodes();
} catch (\Throwable) {
    $orphanQuizCodes = [];
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

$escape = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
$formatPercent = static fn (mixed $value): string => number_format((float)$value, 1, '.', '') . '%';
$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$periodUrl = static fn (string $value): string => '/bitrix/admin/kk_quiz_statistics.php?' . http_build_query(['lang' => $lang, 'period' => $value]);
$totals = is_array($summary['totals'] ?? null) ? $summary['totals'] : [];
$cards = [
    'Всего заявок' => (int)($totals['all'] ?? 0),
    'Сегодня' => (int)($totals['today'] ?? 0),
    '7 дней' => (int)($totals['last_7_days'] ?? 0),
    '30 дней' => (int)($totals['last_30_days'] ?? 0),
    'За выбранный период' => (int)($summary['period_total'] ?? 0),
];
$periodLabel = (string)($summary['period']['label'] ?? '');
?>
<style>
.kk-quiz-stat-cards{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 18px;}
.kk-quiz-stat-card{min-width:160px;padding:14px 16px;border:1px solid #d6d6d6;border-radius:6px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);}
.kk-quiz-stat-card__label{color:#666;font-size:12px;margin-bottom:6px;}
.kk-quiz-stat-card__value{font-size:28px;font-weight:bold;line-height:1;}
.kk-quiz-stat-section{margin-top:22px;}
.kk-quiz-stat-actions{margin:10px 0 16px;}
.kk-quiz-stat-empty{padding:12px;color:#777;background:#fff;border:1px solid #d6d6d6;}
.kk-quiz-stat-filter{padding:12px;margin:12px 0 16px;border:1px solid #d6d6d6;background:#fff;border-radius:6px;}
.kk-quiz-stat-filter__presets{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;}
.kk-quiz-stat-filter form{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.kk-quiz-stat-period-label{margin:-6px 0 18px;color:#555;}
.kk-quiz-stat-row-warn{background:#fff8d7;}
.kk-quiz-stat-row-danger{background:#ffe8e8;}
.kk-quiz-stat-note{margin:8px 0 14px;color:#666;font-size:12px;}
.kk-quiz-insight-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin:10px 0 18px;}
.kk-quiz-insight-card{padding:12px 14px;border:1px solid #d6d6d6;border-radius:6px;background:#fff;}
.kk-quiz-insight-card__title{font-weight:bold;margin-bottom:8px;}
.kk-quiz-insight-card__muted{color:#666;}
.kk-quiz-maintenance{padding:12px;margin:12px 0 18px;border:1px solid #d6d6d6;background:#fff;border-radius:6px;}
.kk-quiz-maintenance__actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
.kk-quiz-maintenance__note{margin-top:8px;color:#666;font-size:12px;}
</style>

<?php if ($error !== ''): ?>
    <div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message"><?= $escape($error) ?></div></div>
<?php endif; ?>

<?php foreach ((array)($summary['warnings'] ?? []) as $warning): ?>
    <div class="adm-info-message-wrap"><div class="adm-info-message"><?= $escape($warning) ?></div></div>
<?php endforeach; ?>
<?php foreach ((array)($funnelSummary['warnings'] ?? []) as $warning): ?>
    <div class="adm-info-message-wrap"><div class="adm-info-message"><?= $escape($warning) ?></div></div>
<?php endforeach; ?>

<div class="kk-quiz-stat-filter">
    <div class="kk-quiz-stat-filter__presets">
        <strong>Период:</strong>
        <?php foreach (['today' => 'Сегодня', '7d' => '7 дней', '30d' => '30 дней', 'all' => 'Всё время'] as $periodValue => $periodText): ?>
            <a class="<?= $period === $periodValue ? 'adm-btn-save' : 'adm-btn' ?>" href="<?= $escape($periodUrl($periodValue)) ?>"><?= $escape($periodText) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" action="/bitrix/admin/kk_quiz_statistics.php">
        <input type="hidden" name="lang" value="<?= $escape($lang) ?>">
        <input type="hidden" name="period" value="custom">
        <label>С: <input type="date" name="date_from" value="<?= $escape($customDateFrom) ?>"></label>
        <label>По: <input type="date" name="date_to" value="<?= $escape($customDateTo) ?>"></label>
        <button type="submit" class="<?= $period === 'custom' ? 'adm-btn-save' : 'adm-btn' ?>">Показать</button>
    </form>
</div>

<div class="kk-quiz-stat-actions">
    <?php if ((string)($summary['leads_admin_url'] ?? '') !== ''): ?>
        <a class="adm-btn" href="<?= $escape($summary['leads_admin_url']) ?>">Открыть список заявок</a>
    <?php endif; ?>
</div>


<?php
$orphanQuizCodes = is_array($orphanQuizCodes ?? null) ? $orphanQuizCodes : [];
$orphanPreview = array_slice($orphanQuizCodes, 0, 10);
$orphanExtraCount = max(0, count($orphanQuizCodes) - count($orphanPreview));
?>
<div class="kk-quiz-maintenance">
    <h2>Обслуживание статистики</h2>
    <div>Срок хранения событий: <?= $escape(QuizEventMaintenanceService::DEFAULT_RETENTION_DAYS) ?> дней</div>
    <?php if ($orphanQuizCodes === []): ?>
        <div class="kk-quiz-maintenance__note">Статистика удалённых квизов не найдена.</div>
    <?php else: ?>
        <div class="kk-quiz-maintenance__note">
            Найдены события по удалённым квизам: <?= $escape(implode(', ', $orphanPreview)) ?><?= $orphanExtraCount > 0 ? $escape(' и ещё ' . $orphanExtraCount) : '' ?>
        </div>
    <?php endif; ?>
    <div class="kk-quiz-maintenance__actions">
        <button type="button" class="adm-btn" data-kk-quiz-cleanup="old">Очистить старые события</button>
        <button type="button" class="adm-btn" data-kk-quiz-cleanup="orphan">Очистить статистику удалённых квизов</button>
        <button type="button" class="adm-btn adm-btn-save" data-kk-quiz-cleanup="all">Выполнить полную очистку</button>
    </div>
</div>
<script>
(function () {
    const messages = {
        old: 'Очистить старые события аналитики? Это действие нельзя отменить.',
        orphan: 'Очистить статистику квизов, которые уже удалены? Это действие нельзя отменить.',
        all: 'Выполнить полную очистку старых событий и статистики удалённых квизов? Это действие нельзя отменить.'
    };

    const getCleanupUrl = () => {
        const sessid = window.BX && typeof BX.bitrix_sessid === 'function' ? BX.bitrix_sessid() : '';
        const params = new URLSearchParams({ action: 'kk:quiz.api.cleanupQuizEvents' });
        if (sessid !== '') {
            params.set('sessid', sessid);
        }

        return '/bitrix/services/main/ajax.php?' + params.toString();
    };

    const getDeletedCount = (data) => {
        let deleted = 0;
        if (data && data.old && Number.isFinite(Number(data.old.deleted))) {
            deleted += Number(data.old.deleted);
        }
        if (data && data.orphan && Number.isFinite(Number(data.orphan.deleted))) {
            deleted += Number(data.orphan.deleted);
        }

        return deleted;
    };

    document.querySelectorAll('[data-kk-quiz-cleanup]').forEach((button) => {
        button.addEventListener('click', () => {
            const mode = button.getAttribute('data-kk-quiz-cleanup') || '';
            if (!messages[mode] || !confirm(messages[mode])) {
                return;
            }

            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Очистка...';

            fetch(getCleanupUrl(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ mode })
            })
                .then((response) => response.json())
                .then((response) => {
                    const data = response && response.data ? response.data : response;
                    if (!data || data.success !== true) {
                        console.warn('KK Quiz: cleanup failed', response);
                        throw new Error('CLEANUP_FAILED');
                    }

                    alert('Удалено событий: ' + getDeletedCount(data));
                    window.location.reload();
                })
                .catch((error) => {
                    console.warn('KK Quiz: cleanup failed', { error });
                    alert('Не удалось выполнить очистку статистики. Подробности в консоли.');
                })
                .finally(() => {
                    button.disabled = false;
                    button.textContent = originalText;
                });
        });
    });
})();
</script>

<div class="kk-quiz-stat-cards">
    <?php foreach ($cards as $label => $value): ?>
        <div class="kk-quiz-stat-card">
            <div class="kk-quiz-stat-card__label"><?= $escape($label) ?></div>
            <div class="kk-quiz-stat-card__value"><?= $escape($value) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="kk-quiz-stat-period-label">Период таблиц: <?= $escape($periodLabel !== '' ? $periodLabel : 'всё время') ?></div>

<?php
$funnelCards = is_array($funnelSummary['summary_cards'] ?? null) ? $funnelSummary['summary_cards'] : [];
$insights = is_array($funnelSummary['insights'] ?? null) ? $funnelSummary['insights'] : [];
$worstDropoff = is_array($insights['worst_dropoff_question'] ?? null) ? $insights['worst_dropoff_question'] : null;
$lowestAnswerRate = is_array($insights['lowest_answer_rate_question'] ?? null) ? $insights['lowest_answer_rate_question'] : null;
$lowestStartToLead = is_array($insights['lowest_start_to_lead_quiz'] ?? null) ? $insights['lowest_start_to_lead_quiz'] : null;
$funnelCardItems = [
    'Показов квиза' => (int)($funnelCards['views'] ?? 0),
    'Начали прохождение' => (int)($funnelCards['starts'] ?? 0),
    'Дошли до результата' => (int)($funnelCards['results'] ?? 0),
    'Увидели форму' => (int)($funnelCards['forms'] ?? 0),
    'Отправили заявку' => (int)($funnelCards['leads'] ?? 0),
    'View → Lead' => $formatPercent($funnelCards['view_to_lead'] ?? 0),
    'Start → Lead' => $formatPercent($funnelCards['start_to_lead'] ?? 0),
];
?>

<div class="kk-quiz-stat-section">
    <h2>Сводка по прохождению квиза</h2>
    <div class="kk-quiz-stat-cards">
        <?php foreach ($funnelCardItems as $label => $value): ?>
            <div class="kk-quiz-stat-card">
                <div class="kk-quiz-stat-card__label"><?= $escape($label) ?></div>
                <div class="kk-quiz-stat-card__value"><?= $escape($value) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="kk-quiz-stat-note">
        Показ = событие quiz_view. Старт = первый ответ на первый вопрос. Результат = показ результата. Форма = показ финальной формы. Заявка = успешно сохранённая заявка.
    </div>
</div>

<div class="kk-quiz-stat-section">
    <h2>Главные проблемы</h2>
    <div class="kk-quiz-insight-grid">
        <div class="kk-quiz-insight-card">
            <div class="kk-quiz-insight-card__title">Самый большой отвал</div>
            <?php if ($worstDropoff !== null): ?>
                <div><?= $escape($worstDropoff['question_title'] ?? $worstDropoff['question_code'] ?? '') ?></div>
                <div class="kk-quiz-insight-card__muted">Квиз: <?= $escape($worstDropoff['quiz_name'] ?? $worstDropoff['quiz_code'] ?? '') ?></div>
                <div class="kk-quiz-insight-card__muted">Шаг: <?= $escape((int)($worstDropoff['step_index'] ?? 0)) ?></div>
                <div>Показан: <?= $escape((int)($worstDropoff['shown'] ?? 0)) ?>, ответили: <?= $escape((int)($worstDropoff['answered'] ?? 0)) ?>, отвал: <?= $escape((int)($worstDropoff['dropoff'] ?? 0)) ?> (<?= $escape($formatPercent($worstDropoff['dropoff_rate'] ?? 0)) ?>)</div>
            <?php else: ?>
                <div class="kk-quiz-insight-card__muted">Критичных отвалов пока нет.</div>
            <?php endif; ?>
        </div>
        <div class="kk-quiz-insight-card">
            <div class="kk-quiz-insight-card__title">Самая низкая доля ответа</div>
            <?php if ($lowestAnswerRate !== null): ?>
                <div><?= $escape($lowestAnswerRate['question_title'] ?? $lowestAnswerRate['question_code'] ?? '') ?></div>
                <div class="kk-quiz-insight-card__muted">Квиз: <?= $escape($lowestAnswerRate['quiz_name'] ?? $lowestAnswerRate['quiz_code'] ?? '') ?></div>
                <div>Показан: <?= $escape((int)($lowestAnswerRate['shown'] ?? 0)) ?>, ответили: <?= $escape((int)($lowestAnswerRate['answered'] ?? 0)) ?>, ответили <?= $escape($formatPercent($lowestAnswerRate['answer_rate'] ?? 0)) ?></div>
            <?php else: ?>
                <div class="kk-quiz-insight-card__muted">Недостаточно данных для оценки вопросов.</div>
            <?php endif; ?>
        </div>
        <div class="kk-quiz-insight-card">
            <div class="kk-quiz-insight-card__title">Самая низкая конверсия квиза</div>
            <?php if ($lowestStartToLead !== null): ?>
                <div><?= $escape($lowestStartToLead['quiz_name'] ?? $lowestStartToLead['quiz_code'] ?? '') ?></div>
                <div>Начали: <?= $escape((int)($lowestStartToLead['starts'] ?? 0)) ?>, заявки: <?= $escape((int)($lowestStartToLead['leads'] ?? 0)) ?>, Start→Lead: <?= $escape($formatPercent($lowestStartToLead['start_to_lead'] ?? 0)) ?></div>
            <?php else: ?>
                <div class="kk-quiz-insight-card__muted">Недостаточно данных для оценки конверсии.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="kk-quiz-stat-section">
    <h2>Воронка прохождения квиза</h2>
    <?php if ((array)($funnelSummary['funnel_by_quiz'] ?? []) === []): ?>
        <div class="kk-quiz-stat-empty">Нет данных по событиям квиза за выбранный период.</div>
    <?php else: ?>
        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Квиз</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Показы</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Открытия</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Старты</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Результаты</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Формы</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Заявки</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">View→Lead</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Start→Lead</div></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array)$funnelSummary['funnel_by_quiz'] as $row): ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell"><?= $escape($row['quiz_name'] ?? $row['quiz_code'] ?? '') ?><br><small><?= $escape($row['quiz_code'] ?? '') ?></small></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['views'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['opens'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['starts'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['results'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['forms'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['leads'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape($formatPercent($row['view_to_lead'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape($formatPercent($row['start_to_lead'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="kk-quiz-stat-section">
    <h2>Отвалы по вопросам</h2>
    <?php if ((array)($funnelSummary['question_dropoff'] ?? []) === []): ?>
        <div class="kk-quiz-stat-empty">Нет данных по событиям квиза за выбранный период.</div>
    <?php else: ?>
        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Квиз</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Шаг</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Вопрос</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Показан</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Ответили</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Отвал</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Отвал %</div></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array)$funnelSummary['question_dropoff'] as $row): ?>
                <?php
                $dropoffRate = (float)($row['dropoff_rate'] ?? 0);
                $dropoffClass = $dropoffRate >= 50.0 ? ' kk-quiz-stat-row-danger' : ($dropoffRate >= 25.0 ? ' kk-quiz-stat-row-warn' : '');
                ?>
                <tr class="adm-list-table-row<?= $escape($dropoffClass) ?>">
                    <td class="adm-list-table-cell"><?= $escape($row['quiz_name'] ?? $row['quiz_code'] ?? '') ?><br><small><?= $escape($row['quiz_code'] ?? '') ?></small></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['step_index'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape($row['question_title'] ?? $row['question_code'] ?? '') ?><br><small><?= $escape($row['question_code'] ?? '') ?></small></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['shown'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['answered'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['dropoff'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape($formatPercent($dropoffRate)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="kk-quiz-stat-section">
    <h2>Популярные ответы</h2>
    <div class="kk-quiz-stat-note">
        Для checkbox-вопросов доля считается среди выбранных вариантов, поэтому сумма по вопросу может отличаться от 100% по пользователям.
    </div>
    <?php if ((array)($funnelSummary['popular_answers'] ?? []) === []): ?>
        <div class="kk-quiz-stat-empty">Нет данных по событиям квиза за выбранный период.</div>
    <?php else: ?>
        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Квиз</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Вопрос</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Ответ</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Количество</div></td>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Доля среди выбранных ответов</div></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array)$funnelSummary['popular_answers'] as $row): ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell"><?= $escape($row['quiz_name'] ?? $row['quiz_code'] ?? '') ?><br><small><?= $escape($row['quiz_code'] ?? '') ?></small></td>
                    <td class="adm-list-table-cell"><?= $escape($row['question_title'] ?? $row['question_code'] ?? '') ?><br><small><?= $escape($row['question_code'] ?? '') ?></small></td>
                    <td class="adm-list-table-cell"><?= $escape($row['answer_title'] ?? $row['answer_code'] ?? '') ?><br><small><?= $escape($row['answer_code'] ?? '') ?></small></td>
                    <td class="adm-list-table-cell"><?= $escape((int)($row['count'] ?? 0)) ?></td>
                    <td class="adm-list-table-cell"><?= $escape($formatPercent($row['share'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="kk-quiz-stat-section">
    <h2>По квизам за выбранный период</h2>
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
    <h2>По результатам за выбранный период</h2>
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
    <h2>Последние заявки за выбранный период</h2>
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
