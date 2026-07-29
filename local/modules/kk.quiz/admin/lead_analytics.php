<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Admin\LeadAnalyticsReportBuilder;
use Kk\Quiz\Admin\LeadStatusHelper;
use Kk\Quiz\Iblock\Installer;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

$APPLICATION->SetTitle('KK Quiz — аналитика заявок');
if (!Loader::includeModule('kk.quiz') || !Loader::includeModule('iblock')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Модуль kk.quiz или iblock не установлен.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}
if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
}

$escape = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
$requestValue = static fn (string $name): string => trim((string)($_GET[$name] ?? ''));
$validateDate = static function (string $value): ?int {
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value) !== 1) {
        return null;
    }
    [$day, $month, $year] = array_map('intval', explode('.', $value));
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    $timestamp = mktime(0, 0, 0, $month, $day, $year);

    return $timestamp !== false ? $timestamp : null;
};

$period = $requestValue('period');
$period = in_array($period, ['today', '7d', '30d', 'custom'], true) ? $period : '7d';
$dateFromValue = $requestValue('date_from');
$dateToValue = $requestValue('date_to');
$now = time();
$todayStart = strtotime('today', $now) ?: $now;
$dateFrom = $todayStart - (6 * 86400);
$dateTo = $now;
$periodError = '';

if ($period === 'today') {
    $dateFrom = $todayStart;
} elseif ($period === '30d') {
    $dateFrom = $todayStart - (29 * 86400);
} elseif ($period === 'custom') {
    $customFrom = $validateDate($dateFromValue);
    $customTo = $validateDate($dateToValue);
    if ($customFrom === null || $customTo === null || $customFrom > $customTo) {
        $period = '7d';
        $dateFrom = $todayStart - (6 * 86400);
        $dateTo = $now;
    } elseif (($customTo - $customFrom) > (365 * 86400)) {
        $periodError = 'Период не должен превышать 365 дней.';
    } else {
        $dateFrom = $customFrom;
        $dateTo = $customTo + 86399;
    }
}

$iblock = CIBlock::GetList([], [
    'TYPE' => Installer::IBLOCK_TYPE_ID,
    'CODE' => Installer::LEADS_IBLOCK_CODE,
])->Fetch();
$leadsIblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$report = null;
if ($leadsIblockId > 0 && $periodError === '') {
    $report = (new LeadAnalyticsReportBuilder())->build([
        'IBLOCK_ID' => $leadsIblockId,
        '>=DATE_CREATE' => ConvertTimeStamp($dateFrom, 'FULL'),
        '<=DATE_CREATE' => ConvertTimeStamp($dateTo, 'FULL'),
    ]);
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$context = new CAdminContextMenu([
    ['TEXT' => 'Заявки', 'LINK' => 'kk_quiz_leads.php?' . http_build_query(['lang' => $lang]), 'ICON' => 'btn_list'],
    ['TEXT' => 'Квизы', 'LINK' => 'kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang])],
    ['TEXT' => 'Статистика', 'LINK' => 'kk_quiz_statistics.php?' . http_build_query(['lang' => $lang])],
    ['TEXT' => 'Настройки', 'LINK' => 'settings.php?' . http_build_query(['mid' => 'kk.quiz', 'lang' => $lang])],
]);
$context->Show();

if ($leadsIblockId <= 0) {
    CAdminMessage::ShowMessage('Инфоблок заявок KK Quiz не найден.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}
if ($periodError !== '') {
    CAdminMessage::ShowMessage($periodError);
}

$periodLabels = ['today' => 'Сегодня', '7d' => '7 дней', '30d' => '30 дней', 'custom' => 'Произвольный период'];
$statusLabels = LeadStatusHelper::labels() + ['empty' => 'Без статуса', 'unknown' => 'Неизвестный статус'];
$kpis = $report !== null ? [
    'Всего заявок' => $report['total'],
    'Новые' => $report['statuses']['new'],
    'Требуют внимания' => $report['attention'],
    'В работе' => $report['statuses']['in_progress'],
    'Связались' => $report['statuses']['contacted'],
    'Сделка создана' => $report['statuses']['deal_created'],
    'Закрыта' => $report['statuses']['closed'],
    'Отказ' => $report['statuses']['rejected'],
    'Спам' => $report['statuses']['spam'],
] : [];
?>
<style>
.kk-lead-analytics-periods{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.kk-lead-analytics-periods a{padding:7px 12px;border:1px solid #cdd2d7;border-radius:4px;background:#fff;text-decoration:none}.kk-lead-analytics-periods a.is-active{background:#dbeeff;border-color:#8dbdea;font-weight:700}.kk-lead-analytics-custom{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:12px 0 18px}.kk-lead-analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0}.kk-lead-analytics-card{box-sizing:border-box;padding:16px;border:1px solid #dce1e6;border-radius:6px;background:#fff}.kk-lead-analytics-card h2{margin:0 0 14px;font-size:18px}.kk-lead-analytics-kpi__value{font-size:28px;font-weight:700;color:#263238}.kk-lead-analytics-kpi__label{margin-top:5px;color:#66727c}.kk-lead-analytics-columns{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin:16px 0}.kk-lead-analytics-bar-row{display:grid;grid-template-columns:minmax(115px,1fr) 3fr 45px;align-items:center;gap:10px;margin:9px 0}.kk-lead-analytics-bar{height:12px;overflow:hidden;border-radius:6px;background:#edf0f2}.kk-lead-analytics-bar__fill{height:100%;min-width:2px;border-radius:6px;background:#6da7d9}.kk-lead-analytics-table{width:100%;border-collapse:collapse}.kk-lead-analytics-table th,.kk-lead-analytics-table td{padding:8px;border-bottom:1px solid #e5e8eb;text-align:left}.kk-lead-analytics-table th:last-child,.kk-lead-analytics-table td:last-child{text-align:right}.kk-lead-analytics-empty{padding:18px;border:1px solid #dce1e6;background:#fff;color:#66727c}
</style>
<h1>Аналитика заявок</h1>
<div class="kk-lead-analytics-periods">
<?php foreach ($periodLabels as $code => $label):
    $parameters = ['period' => $code, 'lang' => $lang];
?>
    <a class="<?= $period === $code ? 'is-active' : '' ?>" href="<?= $escape('kk_quiz_lead_analytics.php?' . http_build_query($parameters)) ?>"><?= $escape($label) ?></a>
<?php endforeach; ?>
</div>
<form class="kk-lead-analytics-custom" method="get">
    <input type="hidden" name="lang" value="<?= $escape($lang) ?>">
    <input type="hidden" name="period" value="custom">
    <label>С <input type="text" name="date_from" value="<?= $escape($dateFromValue) ?>" placeholder="ДД.ММ.ГГГГ"></label>
    <label>по <input type="text" name="date_to" value="<?= $escape($dateToValue) ?>" placeholder="ДД.ММ.ГГГГ"></label>
    <button class="adm-btn adm-btn-save" type="submit">Показать</button>
</form>
<?php if ($report !== null && $report['total'] === 0): ?>
    <div class="kk-lead-analytics-empty">За выбранный период заявок нет.</div>
<?php elseif ($report !== null): ?>
<div class="kk-lead-analytics-grid">
    <?php foreach ($kpis as $label => $value): ?>
    <div class="kk-lead-analytics-card kk-lead-analytics-kpi"><div class="kk-lead-analytics-kpi__value"><?= (int)$value ?></div><div class="kk-lead-analytics-kpi__label"><?= $escape($label) ?></div></div>
    <?php endforeach; ?>
</div>
<div class="kk-lead-analytics-grid">
    <?php foreach (['deal_created_percent' => 'Заявка → сделка создана', 'closed_percent' => 'Заявка → закрыта', 'rejected_percent' => 'Заявка → отказ'] as $key => $label): ?>
    <div class="kk-lead-analytics-card kk-lead-analytics-kpi"><div class="kk-lead-analytics-kpi__value"><?= $escape(number_format((float)$report['conversion'][$key], 1, ',', '')) ?>%</div><div class="kk-lead-analytics-kpi__label"><?= $escape($label) ?></div></div>
    <?php endforeach; ?>
</div>
<div class="kk-lead-analytics-columns">
    <div class="kk-lead-analytics-card"><h2>Распределение по статусам</h2>
    <?php foreach ($report['statuses'] as $status => $count): $width = $report['total'] > 0 ? $count / $report['total'] * 100 : 0; ?>
        <div class="kk-lead-analytics-bar-row"><span><?= $escape($statusLabels[$status] ?? $status) ?></span><div class="kk-lead-analytics-bar"><div class="kk-lead-analytics-bar__fill" style="width:<?= $escape(round($width, 1)) ?>%"></div></div><strong><?= (int)$count ?></strong></div>
    <?php endforeach; ?></div>
    <div class="kk-lead-analytics-card"><h2>Заявки по дням</h2>
    <?php $dailyMax = max(array_column($report['daily'], 'count') ?: [1]); foreach ($report['daily'] as $item): ?>
        <div class="kk-lead-analytics-bar-row"><span><?= $escape($item['label']) ?></span><div class="kk-lead-analytics-bar"><div class="kk-lead-analytics-bar__fill" style="width:<?= $escape(round($item['count'] / $dailyMax * 100, 1)) ?>%"></div></div><strong><?= (int)$item['count'] ?></strong></div>
    <?php endforeach; ?></div>
</div>
<?php
$renderNamedTable = static function (string $title, array $items, string $labelKey, string $labelTitle) use ($escape): void { ?>
    <div class="kk-lead-analytics-card"><h2><?= $escape($title) ?></h2><table class="kk-lead-analytics-table"><thead><tr><th><?= $escape($labelTitle) ?></th><th>Код</th><th>Заявок</th></tr></thead><tbody>
    <?php foreach ($items as $item): ?><tr><td><?= $escape($item[$labelKey]) ?></td><td><?= $escape($item['code']) ?></td><td><?= (int)$item['count'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php };
$renderValueTable = static function (string $title, string $column, array $items) use ($escape): void { ?>
    <div class="kk-lead-analytics-card"><h2><?= $escape($title) ?></h2><table class="kk-lead-analytics-table"><thead><tr><th><?= $escape($column) ?></th><th>Заявок</th></tr></thead><tbody>
    <?php foreach ($items as $item): ?><tr><td><?= $escape($item['value']) ?></td><td><?= (int)$item['count'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php };
?>
<div class="kk-lead-analytics-columns">
    <?php $renderNamedTable('Топ квизов', $report['top_quizzes'], 'name', 'Квиз'); ?>
    <?php $renderNamedTable('Топ результатов', $report['top_results'], 'title', 'Результат'); ?>
    <?php $renderValueTable('Источники', 'utm_source', $report['top_utm_sources']); ?>
    <?php $renderValueTable('Кампании', 'utm_campaign', $report['top_utm_campaigns']); ?>
</div>
<?php endif; ?>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
