<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Kk\Quiz\Analytics\QuizEventTable;
use Kk\Quiz\Iblock\Installer;

final class QuizFunnelStatisticsService
{
    private const LIMIT = 100000;
    private const POPULAR_ANSWERS_LIMIT = 100;

    public function getSummary(array $options = []): array
    {
        $summary = [
            'summary_cards' => $this->createEmptySummaryCards(),
            'insights' => $this->createEmptyInsights(),
            'funnel_by_quiz' => [],
            'question_dropoff' => [],
            'popular_answers' => [],
            'warnings' => [],
        ];

        try {
            if (!Application::getConnection()->isTableExists(QuizEventTable::getTableName())) {
                return $summary;
            }
        } catch (\Throwable) {
            return $summary;
        }

        $dateFrom = $this->normalizeTimestamp($options['date_from'] ?? null);
        $dateTo = $this->normalizeTimestamp($options['date_to'] ?? null, true);
        $quizCode = $this->normalizeQuizCode($options['quiz_code'] ?? '');
        $events = $this->loadEvents($dateFrom, $dateTo, $quizCode, $summary['warnings']);
        if ($events === []) {
            return $summary;
        }

        $funnel = [];
        $questionStats = [];
        $answerStats = [];
        $questionAnswerTotals = [];
        $quizCodes = [];
        $questionIds = [];
        $questionCodes = [];

        foreach ($events as $event) {
            $quizCode = trim((string)($event['QUIZ_CODE'] ?? ''));
            $runId = trim((string)($event['RUN_ID'] ?? ''));
            $eventType = trim((string)($event['EVENT_TYPE'] ?? ''));
            if ($quizCode === '' || $runId === '' || $eventType === '') {
                continue;
            }

            $quizCodes[$quizCode] = true;
            $questionCode = trim((string)($event['QUESTION_CODE'] ?? ''));
            $answerCode = trim((string)($event['ANSWER_CODE'] ?? ''));
            $stepIndex = (int)($event['STEP_INDEX'] ?? 0);
            $questionId = (int)($event['QUESTION_ID'] ?? 0);
            if ($questionId > 0) {
                $questionIds[$questionId] = true;
            }
            if ($questionCode !== '') {
                $questionCodes[$questionCode] = true;
            }

            if (!isset($funnel[$quizCode])) {
                $funnel[$quizCode] = $this->createFunnelBuckets();
            }
            if (isset($funnel[$quizCode][$eventType])) {
                $funnel[$quizCode][$eventType][$runId] = true;
            }

            if (($eventType === 'question_show' || $eventType === 'question_answer') && $questionCode !== '') {
                $questionKey = $quizCode . '|' . $questionCode . '|' . $stepIndex;
                if (!isset($questionStats[$questionKey])) {
                    $questionStats[$questionKey] = [
                        'quiz_code' => $quizCode,
                        'question_code' => $questionCode,
                        'question_id' => $questionId,
                        'step_index' => $stepIndex,
                        'shown_runs' => [],
                        'answered_runs' => [],
                    ];
                }
                if ($questionId > 0 && (int)$questionStats[$questionKey]['question_id'] <= 0) {
                    $questionStats[$questionKey]['question_id'] = $questionId;
                }
                if ($eventType === 'question_show') {
                    $questionStats[$questionKey]['shown_runs'][$runId] = true;
                } else {
                    $questionStats[$questionKey]['answered_runs'][$runId] = true;
                }
            }

            if ($eventType === 'question_answer' && $questionCode !== '' && $answerCode !== '') {
                $answerKey = $quizCode . '|' . $questionCode . '|' . $answerCode;
                if (!isset($answerStats[$answerKey])) {
                    $answerStats[$answerKey] = [
                        'quiz_code' => $quizCode,
                        'question_code' => $questionCode,
                        'question_id' => $questionId,
                        'answer_code' => $answerCode,
                        'runs' => [],
                    ];
                }
                if ($questionId > 0 && (int)$answerStats[$answerKey]['question_id'] <= 0) {
                    $answerStats[$answerKey]['question_id'] = $questionId;
                }
                $answerStats[$answerKey]['runs'][$runId] = true;
                $questionTotalKey = $quizCode . '|' . $questionCode;
                $questionAnswerTotals[$questionTotalKey][$runId . '|' . $answerCode] = true;
            }
        }

        $meta = $this->loadMetadata(array_keys($quizCodes), array_keys($questionIds), array_keys($questionCodes));
        $funnelRows = $this->buildFunnelRows($funnel, $meta['quiz_names']);
        $dropoffRows = $this->buildDropoffRows($questionStats, $meta['quiz_names'], $meta['question_titles']);
        $popularAnswerRows = $this->buildPopularAnswerRows($answerStats, $questionAnswerTotals, $meta);

        $summary['summary_cards'] = $this->buildSummaryCards($funnel);
        $summary['funnel_by_quiz'] = $funnelRows;
        $summary['question_dropoff'] = $dropoffRows;
        $summary['popular_answers'] = $popularAnswerRows;
        $summary['insights'] = $this->buildInsights($funnelRows, $dropoffRows);

        return $summary;
    }

    public function getAvailableQuizzes(): array
    {
        try {
            if (!Application::getConnection()->isTableExists(QuizEventTable::getTableName())) {
                return [];
            }

            $codes = [];
            $result = QuizEventTable::getList([
                'select' => ['QUIZ_CODE'],
                'group' => ['QUIZ_CODE'],
                'order' => ['QUIZ_CODE' => 'ASC'],
            ]);
            while ($row = $result->fetch()) {
                $code = $this->normalizeQuizCode($row['QUIZ_CODE'] ?? '');
                if ($code !== '') {
                    $codes[$code] = $code;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        if ($codes === []) {
            return [];
        }

        $meta = $this->loadMetadata(array_values($codes), [], []);
        $quizzes = [];
        foreach (array_values($codes) as $code) {
            $quizzes[] = [
                'code' => $code,
                'name' => (string)($meta['quiz_names'][$code] ?? $code),
            ];
        }

        usort($quizzes, static fn (array $left, array $right): int => strcmp((string)$left['name'], (string)$right['name'])
            ?: strcmp((string)$left['code'], (string)$right['code']));

        return $quizzes;
    }

    private function createEmptySummaryCards(): array
    {
        return [
            'views' => 0,
            'starts' => 0,
            'results' => 0,
            'forms' => 0,
            'leads' => 0,
            'view_to_lead' => 0.0,
            'start_to_lead' => 0.0,
        ];
    }

    private function createEmptyInsights(): array
    {
        return [
            'worst_dropoff_question' => null,
            'lowest_answer_rate_question' => null,
            'lowest_start_to_lead_quiz' => null,
        ];
    }

    private function loadEvents(?int $dateFrom, ?int $dateTo, string $quizCode, array &$warnings): array
    {
        $filter = [];
        if ($dateFrom !== null) {
            $filter['>=DATE_CREATE'] = DateTime::createFromTimestamp($dateFrom);
        }
        if ($dateTo !== null) {
            $filter['<=DATE_CREATE'] = DateTime::createFromTimestamp($dateTo);
        }
        if ($quizCode !== '') {
            $filter['=QUIZ_CODE'] = $quizCode;
        }

        $events = [];
        $result = QuizEventTable::getList([
            'select' => [
                'ID',
                'DATE_CREATE',
                'QUIZ_CODE',
                'RUN_ID',
                'EVENT_TYPE',
                'STEP_INDEX',
                'QUESTION_ID',
                'QUESTION_CODE',
                'ANSWER_CODE',
                'RESULT_ID',
                'RESULT_CODE',
                'LEAD_ID',
            ],
            'filter' => $filter,
            'order' => ['DATE_CREATE' => 'DESC', 'ID' => 'DESC'],
            'limit' => self::LIMIT + 1,
        ]);

        while ($event = $result->fetch()) {
            if (count($events) >= self::LIMIT) {
                $warnings[] = 'Воронка построена по последним 100000 событиям за выбранный период.';
                break;
            }

            $events[] = $event;
        }

        return $events;
    }

    private function createFunnelBuckets(): array
    {
        return [
            'quiz_view' => [],
            'quiz_open' => [],
            'quiz_start' => [],
            'result_show' => [],
            'form_show' => [],
            'lead_success' => [],
        ];
    }

    private function buildSummaryCards(array $funnel): array
    {
        $views = [];
        $starts = [];
        $results = [];
        $forms = [];
        $leads = [];

        foreach ($funnel as $quizCode => $events) {
            foreach ($events['quiz_view'] ?? [] as $runId => $_) {
                $views[$quizCode . '|' . $runId] = true;
            }
            foreach ($events['quiz_start'] ?? [] as $runId => $_) {
                $starts[$quizCode . '|' . $runId] = true;
            }
            foreach ($events['result_show'] ?? [] as $runId => $_) {
                $results[$quizCode . '|' . $runId] = true;
            }
            foreach ($events['form_show'] ?? [] as $runId => $_) {
                $forms[$quizCode . '|' . $runId] = true;
            }
            foreach ($events['lead_success'] ?? [] as $runId => $_) {
                $leads[$quizCode . '|' . $runId] = true;
            }
        }

        $viewsCount = count($views);
        $startsCount = count($starts);
        $leadsCount = count($leads);

        return [
            'views' => $viewsCount,
            'starts' => $startsCount,
            'results' => count($results),
            'forms' => count($forms),
            'leads' => $leadsCount,
            'view_to_lead' => $this->percent($leadsCount, $viewsCount),
            'start_to_lead' => $this->percent($leadsCount, $startsCount),
        ];
    }

    private function buildInsights(array $funnelRows, array $dropoffRows): array
    {
        return [
            'worst_dropoff_question' => $this->findWorstDropoffQuestion($dropoffRows),
            'lowest_answer_rate_question' => $this->findLowestAnswerRateQuestion($dropoffRows),
            'lowest_start_to_lead_quiz' => $this->findLowestStartToLeadQuiz($funnelRows),
        ];
    }

    private function findWorstDropoffQuestion(array $dropoffRows): ?array
    {
        $rows = array_values(array_filter(
            $dropoffRows,
            static fn (array $row): bool => (int)($row['dropoff'] ?? 0) > 0
        ));
        if ($rows === []) {
            return null;
        }

        usort($rows, static fn (array $left, array $right): int => ((int)($right['dropoff'] ?? 0) <=> (int)($left['dropoff'] ?? 0))
            ?: ((float)($right['dropoff_rate'] ?? 0) <=> (float)($left['dropoff_rate'] ?? 0)));

        return $rows[0];
    }

    private function findLowestAnswerRateQuestion(array $dropoffRows): ?array
    {
        $rows = array_values(array_filter(
            $dropoffRows,
            static fn (array $row): bool => (int)($row['shown'] ?? 0) >= 5
        ));
        if ($rows === []) {
            return null;
        }

        usort($rows, static fn (array $left, array $right): int => ((float)($left['answer_rate'] ?? 0) <=> (float)($right['answer_rate'] ?? 0))
            ?: ((int)($right['shown'] ?? 0) <=> (int)($left['shown'] ?? 0)));

        return $rows[0];
    }

    private function findLowestStartToLeadQuiz(array $funnelRows): ?array
    {
        $rows = array_values(array_filter(
            $funnelRows,
            static fn (array $row): bool => (int)($row['starts'] ?? 0) >= 5
        ));
        if ($rows === []) {
            return null;
        }

        usort($rows, static fn (array $left, array $right): int => ((float)($left['start_to_lead'] ?? 0) <=> (float)($right['start_to_lead'] ?? 0))
            ?: ((int)($right['starts'] ?? 0) <=> (int)($left['starts'] ?? 0)));

        $row = $rows[0];

        return [
            'quiz_code' => (string)($row['quiz_code'] ?? ''),
            'quiz_name' => (string)($row['quiz_name'] ?? ''),
            'starts' => (int)($row['starts'] ?? 0),
            'leads' => (int)($row['leads'] ?? 0),
            'start_to_lead' => (float)($row['start_to_lead'] ?? 0.0),
        ];
    }

    private function buildFunnelRows(array $funnel, array $quizNames): array
    {
        $rows = [];
        foreach ($funnel as $quizCode => $events) {
            $views = count($events['quiz_view'] ?? []);
            $opens = count($events['quiz_open'] ?? []);
            $starts = count($events['quiz_start'] ?? []);
            $results = count($events['result_show'] ?? []);
            $forms = count($events['form_show'] ?? []);
            $leads = count($events['lead_success'] ?? []);

            $rows[] = [
                'quiz_code' => (string)$quizCode,
                'quiz_name' => $quizNames[$quizCode] ?? (string)$quizCode,
                'views' => $views,
                'opens' => $opens,
                'starts' => $starts,
                'results' => $results,
                'forms' => $forms,
                'leads' => $leads,
                'view_to_start' => $this->percent($starts, $views),
                'start_to_result' => $this->percent($results, $starts),
                'result_to_form' => $this->percent($forms, $results),
                'form_to_lead' => $this->percent($leads, $forms),
                'start_to_lead' => $this->percent($leads, $starts),
                'view_to_lead' => $this->percent($leads, $views),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => ($right['views'] <=> $left['views']) ?: strcmp((string)$left['quiz_code'], (string)$right['quiz_code']));

        return $rows;
    }

    private function buildDropoffRows(array $questionStats, array $quizNames, array $questionTitles): array
    {
        $rows = [];
        foreach ($questionStats as $row) {
            $shown = count($row['shown_runs']);
            $answered = count($row['answered_runs']);
            $dropoff = max(0, $shown - $answered);
            $questionCode = (string)$row['question_code'];

            $rows[] = [
                'quiz_code' => (string)$row['quiz_code'],
                'quiz_name' => $quizNames[$row['quiz_code']] ?? (string)$row['quiz_code'],
                'question_code' => $questionCode,
                'question_id' => (int)$row['question_id'],
                'question_title' => $this->getQuestionTitle($row, $questionTitles),
                'step_index' => (int)$row['step_index'],
                'shown' => $shown,
                'answered' => $answered,
                'dropoff' => $dropoff,
                'answer_rate' => $this->percent($answered, $shown),
                'dropoff_rate' => $this->percent($dropoff, $shown),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string)$left['quiz_code'], (string)$right['quiz_code'])
            ?: ((int)$left['step_index'] <=> (int)$right['step_index'])
            ?: ((int)$right['dropoff'] <=> (int)$left['dropoff']));

        return $rows;
    }

    private function buildPopularAnswerRows(array $answerStats, array $questionAnswerTotals, array $meta): array
    {
        $rows = [];
        foreach ($answerStats as $row) {
            $quizCode = (string)$row['quiz_code'];
            $questionCode = (string)$row['question_code'];
            $answerCode = (string)$row['answer_code'];
            $count = count($row['runs']);
            $questionTotalKey = $quizCode . '|' . $questionCode;
            $total = count($questionAnswerTotals[$questionTotalKey] ?? []);

            $rows[] = [
                'quiz_code' => $quizCode,
                'quiz_name' => $meta['quiz_names'][$quizCode] ?? $quizCode,
                'question_code' => $questionCode,
                'question_title' => $this->getQuestionTitle($row, $meta['question_titles']),
                'answer_code' => $answerCode,
                'answer_title' => $this->getAnswerTitle($row, $answerCode, $meta['answer_titles']),
                'count' => $count,
                'share' => $this->percent($count, $total),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string)$left['quiz_code'], (string)$right['quiz_code'])
            ?: strcmp((string)$left['question_code'], (string)$right['question_code'])
            ?: ((int)$right['count'] <=> (int)$left['count']));

        return array_slice($rows, 0, self::POPULAR_ANSWERS_LIMIT);
    }

    private function loadMetadata(array $quizCodes, array $questionIds, array $questionCodes): array
    {
        $meta = [
            'quiz_names' => [],
            'question_titles' => ['id' => [], 'code' => []],
            'answer_titles' => [],
        ];

        if (!Loader::includeModule('iblock')) {
            return $meta;
        }

        $iblockId = $this->getQuizIblockId();
        if ($iblockId === null) {
            return $meta;
        }

        $meta['quiz_names'] = $this->loadQuizNames($iblockId, $quizCodes);
        $this->loadQuestionMetadata($iblockId, $questionIds, $questionCodes, $meta);

        return $meta;
    }

    private function loadQuizNames(int $iblockId, array $quizCodes): array
    {
        $names = [];
        $quizCodes = array_values(array_filter(array_unique(array_map('strval', $quizCodes)), static fn (string $code): bool => $code !== ''));
        if ($quizCodes === []) {
            return $names;
        }

        $sections = \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, '=CODE' => $quizCodes],
            false,
            ['ID', 'CODE', 'NAME']
        );
        while ($section = $sections->Fetch()) {
            $code = (string)($section['CODE'] ?? '');
            if ($code !== '') {
                $names[$code] = (string)($section['NAME'] ?? $code);
            }
        }

        return $names;
    }

    private function loadQuestionMetadata(int $iblockId, array $questionIds, array $questionCodes, array &$meta): void
    {
        $filters = [];
        $questionIds = array_values(array_filter(array_map('intval', $questionIds), static fn (int $id): bool => $id > 0));
        if ($questionIds !== []) {
            $filters[] = ['ID' => $questionIds];
        }

        $questionCodes = array_values(array_filter(array_unique(array_map('strval', $questionCodes)), static fn (string $code): bool => $code !== ''));
        if ($questionCodes !== []) {
            $filters[] = ['=CODE' => $questionCodes];
        }

        foreach ($filters as $filter) {
            $elements = \CIBlockElement::GetList(
                [],
                array_merge(['IBLOCK_ID' => $iblockId], $filter),
                false,
                false,
                ['ID', 'IBLOCK_ID', 'CODE', 'NAME']
            );
            while ($elementObject = $elements->GetNextElement()) {
                $fields = $elementObject->GetFields();
                $properties = $elementObject->GetProperties();
                $id = (int)($fields['ID'] ?? 0);
                $code = (string)($fields['CODE'] ?? '');
                $title = $this->getPropertyValue($properties, 'KK_PUBLIC_TITLE');
                if ($title === '') {
                    $title = (string)($fields['NAME'] ?? $code);
                }

                if ($id > 0) {
                    $meta['question_titles']['id'][$id] = $title;
                }
                if ($code !== '') {
                    $meta['question_titles']['code'][$code] = $title;
                }

                $answerTitles = $this->decodeAnswerTitles($this->getPropertyRawValue($properties, 'KK_ANSWERS'));
                foreach ($answerTitles as $answerCode => $answerTitle) {
                    if ($code !== '') {
                        $meta['answer_titles'][$code . '|' . $answerCode] = $answerTitle;
                    }
                    if ($id > 0) {
                        $meta['answer_titles'][$id . '|' . $answerCode] = $answerTitle;
                    }
                }
            }
        }
    }

    private function decodeAnswerTitles(mixed $value): array
    {
        if (is_array($value) && isset($value['TEXT'])) {
            $value = $value['TEXT'];
        }
        if (is_array($value) && isset($value['VALUE'])) {
            $value = $value['VALUE'];
        }
        if (is_array($value) && count($value) === 1) {
            $first = reset($value);
            if (is_string($first)) {
                $value = $first;
            }
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $titles = [];
        foreach (array_values($value) as $index => $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $answerCode = trim((string)($answer['code'] ?? ''));
            if ($answerCode === '') {
                $answerCode = 'answer_' . ($index + 1);
            }
            $answerTitle = trim((string)($answer['text'] ?? ''));
            $titles[$answerCode] = $answerTitle !== '' ? $answerTitle : $answerCode;
        }

        return $titles;
    }

    private function getQuestionTitle(array $row, array $questionTitles): string
    {
        $questionId = (int)($row['question_id'] ?? 0);
        $questionCode = (string)($row['question_code'] ?? '');

        if ($questionId > 0 && isset($questionTitles['id'][$questionId])) {
            return (string)$questionTitles['id'][$questionId];
        }
        if ($questionCode !== '' && isset($questionTitles['code'][$questionCode])) {
            return (string)$questionTitles['code'][$questionCode];
        }

        return $questionCode;
    }

    private function getAnswerTitle(array $row, string $answerCode, array $answerTitles): string
    {
        if ($answerCode === 'custom') {
            return 'Свой вариант / текстовый ответ';
        }

        $questionId = (int)($row['question_id'] ?? 0);
        $questionCode = (string)($row['question_code'] ?? '');
        if ($questionCode !== '' && isset($answerTitles[$questionCode . '|' . $answerCode])) {
            return (string)$answerTitles[$questionCode . '|' . $answerCode];
        }
        if ($questionId > 0 && isset($answerTitles[$questionId . '|' . $answerCode])) {
            return (string)$answerTitles[$questionId . '|' . $answerCode];
        }

        return $answerCode;
    }

    private function getQuizIblockId(): ?int
    {
        $iblock = \CIBlock::GetList(
            [],
            [
                'TYPE' => Installer::IBLOCK_TYPE_ID,
                'CODE' => Installer::QUIZZES_IBLOCK_CODE,
            ]
        )->Fetch();

        return is_array($iblock) ? (int)$iblock['ID'] : null;
    }

    private function getPropertyValue(array $properties, string $code): string
    {
        $value = $this->getPropertyRawValue($properties, $code);
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function getPropertyRawValue(array $properties, string $code): mixed
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return null;
        }

        return $properties[$code]['~VALUE'] ?? $properties[$code]['VALUE'] ?? null;
    }

    private function percent(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round($part / $total * 100, 1);
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

    private function normalizeQuizCode(mixed $value): string
    {
        $value = trim((string)$value);

        return preg_match('/^[a-zA-Z0-9_-]+$/', $value) === 1 ? $value : '';
    }
}
