<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

final class QuizStatisticsService
{
    private const LIMIT = 10000;
    private const RECENT_LIMIT = 20;

    public function getSummary(array $options = []): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('LEADS_IBLOCK_NOT_FOUND');
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            throw new \RuntimeException('LEADS_IBLOCK_NOT_FOUND');
        }

        $dateFrom = $this->normalizeTimestamp($options['date_from'] ?? null);
        $dateTo = $this->normalizeTimestamp($options['date_to'] ?? null, true);
        $periodStats = $this->buildPeriodStats($iblockId, $dateFrom, $dateTo);

        return array_merge(
            [
                'totals' => $this->buildTotals($iblockId),
                'period' => [
                    'date_from' => $dateFrom !== null ? $this->formatDate($dateFrom) : '',
                    'date_to' => $dateTo !== null ? $this->formatDate($dateTo) : '',
                    'label' => $this->buildPeriodLabel($dateFrom, $dateTo),
                ],
                'leads_admin_url' => $this->buildLeadListUrl($iblockId),
            ],
            $periodStats
        );
    }

    private function buildTotals(int $iblockId): array
    {
        $todayStart = strtotime('today') ?: time();
        $last7Days = strtotime('-7 days') ?: $todayStart;
        $last30Days = strtotime('-30 days') ?: $todayStart;

        return [
            'all' => $this->countElements(['IBLOCK_ID' => $iblockId]),
            'today' => $this->countElements([
                'IBLOCK_ID' => $iblockId,
                '>=DATE_CREATE' => $this->formatBitrixDate($todayStart),
            ]),
            'last_7_days' => $this->countElements([
                'IBLOCK_ID' => $iblockId,
                '>=DATE_CREATE' => $this->formatBitrixDate($last7Days),
            ]),
            'last_30_days' => $this->countElements([
                'IBLOCK_ID' => $iblockId,
                '>=DATE_CREATE' => $this->formatBitrixDate($last30Days),
            ]),
        ];
    }

    private function buildPeriodStats(int $iblockId, ?int $dateFrom, ?int $dateTo): array
    {
        $leadsAdminUrl = $this->buildLeadListUrl($iblockId);
        $stats = [
            'period_total' => 0,
            'by_quiz' => [],
            'by_result' => [],
            'recent' => [],
            'warnings' => [],
        ];
        $byQuiz = [];
        $byResult = [];
        $processed = 0;
        $limited = false;

        $elements = \CIBlockElement::GetList(
            ['DATE_CREATE' => 'DESC', 'ID' => 'DESC'],
            $this->buildPeriodFilter($iblockId, $dateFrom, $dateTo),
            false,
            ['nTopCount' => self::LIMIT + 1],
            ['ID', 'IBLOCK_ID', 'NAME', 'DATE_CREATE']
        );

        while ($elementObject = $elements->GetNextElement()) {
            if ($processed >= self::LIMIT) {
                $limited = true;
                break;
            }

            $element = $elementObject->GetFields();
            $properties = $elementObject->GetProperties();
            $properties = is_array($properties) ? $properties : [];
            $leadTimestamp = $this->parseDateTimestamp((string)($element['DATE_CREATE'] ?? ''));
            $quizCode = $this->getPropertyValue($properties, 'KK_LEAD_QUIZ_CODE');
            $quizName = $this->getPropertyValue($properties, 'KK_LEAD_QUIZ_NAME');
            $resultCode = $this->getPropertyValue($properties, 'KK_LEAD_RESULT_CODE');
            $resultTitle = $this->getPropertyValue($properties, 'KK_LEAD_RESULT_TITLE');

            $stats['period_total']++;

            $quizKey = $quizCode !== '' ? $quizCode : 'Без кода';
            if (!isset($byQuiz[$quizKey])) {
                $byQuiz[$quizKey] = [
                    'quiz_code' => $quizKey,
                    'quiz_name' => $quizName,
                    'count' => 0,
                    'last_lead_at' => (string)($element['DATE_CREATE'] ?? ''),
                    'last_lead_timestamp' => $leadTimestamp,
                    'admin_url' => $leadsAdminUrl,
                ];
            }
            $byQuiz[$quizKey]['count']++;
            if ($quizName !== '' && (string)$byQuiz[$quizKey]['quiz_name'] === '') {
                $byQuiz[$quizKey]['quiz_name'] = $quizName;
            }
            if ($leadTimestamp > (int)$byQuiz[$quizKey]['last_lead_timestamp']) {
                $byQuiz[$quizKey]['last_lead_at'] = (string)($element['DATE_CREATE'] ?? '');
                $byQuiz[$quizKey]['last_lead_timestamp'] = $leadTimestamp;
            }

            $resultKey = $quizKey . '|' . ($resultCode !== '' ? $resultCode : 'Без кода');
            if (!isset($byResult[$resultKey])) {
                $byResult[$resultKey] = [
                    'quiz_code' => $quizKey,
                    'quiz_name' => $quizName,
                    'result_code' => $resultCode !== '' ? $resultCode : 'Без кода',
                    'result_title' => $resultTitle,
                    'count' => 0,
                ];
            }
            $byResult[$resultKey]['count']++;
            if ($quizName !== '' && (string)$byResult[$resultKey]['quiz_name'] === '') {
                $byResult[$resultKey]['quiz_name'] = $quizName;
            }
            if ($resultTitle !== '' && (string)$byResult[$resultKey]['result_title'] === '') {
                $byResult[$resultKey]['result_title'] = $resultTitle;
            }

            if (count($stats['recent']) < self::RECENT_LIMIT) {
                $leadId = (int)($element['ID'] ?? 0);
                $stats['recent'][] = [
                    'id' => $leadId,
                    'date' => (string)($element['DATE_CREATE'] ?? ''),
                    'quiz_name' => $quizName,
                    'quiz_code' => $quizCode,
                    'result_title' => $resultTitle,
                    'client_name' => $this->getPropertyValue($properties, 'KK_LEAD_CLIENT_NAME'),
                    'client_phone' => $this->getPropertyValue($properties, 'KK_LEAD_CLIENT_PHONE'),
                    'client_email' => $this->getPropertyValue($properties, 'KK_LEAD_CLIENT_EMAIL'),
                    'page_url' => $this->getPropertyValue($properties, 'KK_LEAD_PAGE_URL'),
                    'admin_url' => $this->buildLeadEditUrl($iblockId, $leadId),
                ];
            }

            $processed++;
        }

        if ($limited) {
            $stats['warnings'][] = 'Статистика за выбранный период построена по последним 10000 заявкам.';
        }

        $stats['by_quiz'] = array_values($byQuiz);
        usort($stats['by_quiz'], static fn (array $left, array $right): int => ($right['count'] <=> $left['count']) ?: strcmp((string)$left['quiz_name'], (string)$right['quiz_name']));
        foreach ($stats['by_quiz'] as &$quizRow) {
            unset($quizRow['last_lead_timestamp']);
        }
        unset($quizRow);

        $stats['by_result'] = array_values($byResult);
        usort($stats['by_result'], static fn (array $left, array $right): int => ($right['count'] <=> $left['count']) ?: strcmp((string)$left['result_title'], (string)$right['result_title']));

        return $stats;
    }

    private function normalizeTimestamp(mixed $value, bool $endOfDay = false): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $timestamp = (int)$value;
            return $timestamp > 0 ? $timestamp : null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $timestamp = strtotime($value . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
            return is_int($timestamp) && $timestamp > 0 ? $timestamp : null;
        }

        $timestamp = strtotime($value);
        return is_int($timestamp) && $timestamp > 0 ? $timestamp : null;
    }

    private function buildPeriodFilter(int $iblockId, ?int $dateFrom, ?int $dateTo): array
    {
        $filter = ['IBLOCK_ID' => $iblockId];
        if ($dateFrom !== null) {
            $filter['>=DATE_CREATE'] = $this->formatBitrixDate($dateFrom);
        }
        if ($dateTo !== null) {
            $filter['<=DATE_CREATE'] = $this->formatBitrixDate($dateTo);
        }

        return $filter;
    }

    private function countElements(array $filter): int
    {
        return (int)\CIBlockElement::GetList([], $filter, [], false, ['ID']);
    }

    private function getLeadsIblockId(): ?int
    {
        $iblock = \CIBlock::GetList(
            [],
            [
                'TYPE' => Installer::IBLOCK_TYPE_ID,
                'CODE' => Installer::LEADS_IBLOCK_CODE,
            ]
        )->Fetch();

        return is_array($iblock) ? (int)$iblock['ID'] : null;
    }

    private function getPropertyValue(array $properties, string $code): string
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return '';
        }

        $value = $properties[$code]['VALUE'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function parseDateTimestamp(string $date): int
    {
        if ($date !== '' && function_exists('MakeTimeStamp')) {
            $timestamp = (int)MakeTimeStamp($date);
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        $timestamp = strtotime($date);

        return is_int($timestamp) && $timestamp > 0 ? $timestamp : 0;
    }

    private function formatBitrixDate(int $timestamp): string
    {
        if (function_exists('ConvertTimeStamp')) {
            return (string)ConvertTimeStamp($timestamp, 'FULL');
        }

        return date('d.m.Y H:i:s', $timestamp);
    }

    private function formatDate(int $timestamp): string
    {
        return date('d.m.Y', $timestamp);
    }

    private function buildPeriodLabel(?int $dateFrom, ?int $dateTo): string
    {
        if ($dateFrom === null && $dateTo === null) {
            return 'всё время';
        }
        if ($dateFrom !== null && $dateTo !== null) {
            return $this->formatDate($dateFrom) . ' — ' . $this->formatDate($dateTo);
        }
        if ($dateFrom !== null) {
            return 'с ' . $this->formatDate($dateFrom);
        }

        return 'по ' . $this->formatDate((int)$dateTo);
    }

    private function buildLeadListUrl(int $iblockId): string
    {
        return '/bitrix/admin/iblock_element_admin.php?' . http_build_query([
            'IBLOCK_ID' => $iblockId,
            'type' => Installer::IBLOCK_TYPE_ID,
            'lang' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru',
        ]);
    }

    private function buildLeadEditUrl(int $iblockId, int $leadId): string
    {
        if ($leadId <= 0) {
            return '';
        }

        return '/bitrix/admin/iblock_element_edit.php?' . http_build_query([
            'IBLOCK_ID' => $iblockId,
            'type' => Installer::IBLOCK_TYPE_ID,
            'ID' => $leadId,
            'lang' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru',
        ]);
    }
}
