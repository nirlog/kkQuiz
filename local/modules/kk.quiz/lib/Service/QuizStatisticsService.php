<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

final class QuizStatisticsService
{
    private const LIMIT = 10000;
    private const RECENT_LIMIT = 20;

    public function getSummary(array $filter = []): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('LEADS_IBLOCK_NOT_FOUND');
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            throw new \RuntimeException('LEADS_IBLOCK_NOT_FOUND');
        }

        $leadsAdminUrl = $this->buildLeadListUrl($iblockId);
        $summary = [
            'totals' => [
                'all' => 0,
                'today' => 0,
                'last_7_days' => 0,
                'last_30_days' => 0,
            ],
            'by_quiz' => [],
            'by_result' => [],
            'recent' => [],
            'leads_admin_url' => $leadsAdminUrl,
            'warnings' => [],
        ];

        $todayStart = strtotime('today') ?: time();
        $last7Days = strtotime('-7 days') ?: $todayStart;
        $last30Days = strtotime('-30 days') ?: $todayStart;
        $byQuiz = [];
        $byResult = [];
        $processed = 0;
        $limited = false;

        $elements = \CIBlockElement::GetList(
            ['DATE_CREATE' => 'DESC', 'ID' => 'DESC'],
            array_merge(['IBLOCK_ID' => $iblockId], $filter),
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

            $summary['totals']['all']++;
            if ($leadTimestamp >= $todayStart) {
                $summary['totals']['today']++;
            }
            if ($leadTimestamp >= $last7Days) {
                $summary['totals']['last_7_days']++;
            }
            if ($leadTimestamp >= $last30Days) {
                $summary['totals']['last_30_days']++;
            }

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

            if (count($summary['recent']) < self::RECENT_LIMIT) {
                $leadId = (int)($element['ID'] ?? 0);
                $summary['recent'][] = [
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
            $summary['warnings'][] = 'Статистика построена по последним 10000 заявкам';
        }

        $summary['by_quiz'] = array_values($byQuiz);
        usort($summary['by_quiz'], static fn (array $left, array $right): int => ($right['count'] <=> $left['count']) ?: strcmp((string)$left['quiz_name'], (string)$right['quiz_name']));
        foreach ($summary['by_quiz'] as &$quizRow) {
            unset($quizRow['last_lead_timestamp']);
        }
        unset($quizRow);

        $summary['by_result'] = array_values($byResult);
        usort($summary['by_result'], static fn (array $left, array $right): int => ($right['count'] <=> $left['count']) ?: strcmp((string)$left['result_title'], (string)$right['result_title']));

        return $summary;
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
