<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

final class QuizStatisticsExportService
{
    public function exportCsv(array $options = []): array
    {
        $dateFrom = $this->normalizeDate($options['date_from'] ?? null);
        $dateTo = $this->normalizeDate($options['date_to'] ?? null);
        $periodLabel = trim((string)($options['period_label'] ?? ''));

        $summary = (new QuizStatisticsService())->getSummary([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
        $funnelSummary = (new QuizFunnelStatisticsService())->getSummary([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        if ($periodLabel === '') {
            $periodLabel = (string)($summary['period']['label'] ?? '');
        }

        $handle = fopen('php://temp', 'r+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('CSV_STREAM_FAILED');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        $this->putRow($handle, ['Отчёт', 'Статистика KK Quiz']);
        $this->putRow($handle, ['Период', $periodLabel !== '' ? $periodLabel : 'всё время']);
        $this->putRow($handle, ['Дата экспорта', date('d.m.Y H:i:s')]);
        $this->putRow($handle, []);

        $this->writeSummarySection($handle, $funnelSummary);
        $this->writeFunnelSection($handle, (array)($funnelSummary['funnel_by_quiz'] ?? []));
        $this->writeDropoffSection($handle, (array)($funnelSummary['question_dropoff'] ?? []));
        $this->writePopularAnswersSection($handle, (array)($funnelSummary['popular_answers'] ?? []));
        $this->writeLeadsByQuizSection($handle, (array)($summary['by_quiz'] ?? []));
        $this->writeLeadsByResultSection($handle, (array)($summary['by_result'] ?? []));

        rewind($handle);
        $content = (string)stream_get_contents($handle);
        fclose($handle);

        return [
            'filename' => $this->buildFilename($dateFrom, $dateTo),
            'content' => $content,
        ];
    }

    private function writeSummarySection($handle, array $funnelSummary): void
    {
        $cards = is_array($funnelSummary['summary_cards'] ?? null) ? $funnelSummary['summary_cards'] : [];
        $this->putRow($handle, ['Секция', 'Показатель', 'Значение']);
        $this->putRow($handle, ['Сводка', 'Показов квиза', (int)($cards['views'] ?? 0)]);
        $this->putRow($handle, ['Сводка', 'Начали прохождение', (int)($cards['starts'] ?? 0)]);
        $this->putRow($handle, ['Сводка', 'Дошли до результата', (int)($cards['results'] ?? 0)]);
        $this->putRow($handle, ['Сводка', 'Увидели форму', (int)($cards['forms'] ?? 0)]);
        $this->putRow($handle, ['Сводка', 'Отправили заявку', (int)($cards['leads'] ?? 0)]);
        $this->putRow($handle, ['Сводка', 'View → Lead', $this->formatPercent($cards['view_to_lead'] ?? 0)]);
        $this->putRow($handle, ['Сводка', 'Start → Lead', $this->formatPercent($cards['start_to_lead'] ?? 0)]);
        $this->putRow($handle, []);
    }

    private function writeFunnelSection($handle, array $rows): void
    {
        $this->putRow($handle, [
            'Секция', 'Квиз', 'Код квиза', 'Показы', 'Открытия', 'Старты', 'Результаты', 'Формы', 'Заявки',
            'View → Start', 'Start → Result', 'Result → Form', 'Form → Lead', 'View → Lead', 'Start → Lead',
        ]);

        foreach ($rows as $row) {
            $this->putRow($handle, [
                'Воронка по квизам',
                $row['quiz_name'] ?? '',
                $row['quiz_code'] ?? '',
                (int)($row['views'] ?? 0),
                (int)($row['opens'] ?? 0),
                (int)($row['starts'] ?? 0),
                (int)($row['results'] ?? 0),
                (int)($row['forms'] ?? 0),
                (int)($row['leads'] ?? 0),
                $this->formatPercent($row['view_to_start'] ?? 0),
                $this->formatPercent($row['start_to_result'] ?? 0),
                $this->formatPercent($row['result_to_form'] ?? 0),
                $this->formatPercent($row['form_to_lead'] ?? 0),
                $this->formatPercent($row['view_to_lead'] ?? 0),
                $this->formatPercent($row['start_to_lead'] ?? 0),
            ]);
        }
        $this->putRow($handle, []);
    }

    private function writeDropoffSection($handle, array $rows): void
    {
        $this->putRow($handle, ['Секция', 'Квиз', 'Код квиза', 'Шаг', 'Вопрос', 'Код вопроса', 'Показан', 'Ответили', 'Отвал', 'Отвал %', 'Доля ответа %']);

        foreach ($rows as $row) {
            $this->putRow($handle, [
                'Отвалы по вопросам',
                $row['quiz_name'] ?? '',
                $row['quiz_code'] ?? '',
                (int)($row['step_index'] ?? 0),
                $row['question_title'] ?? '',
                $row['question_code'] ?? '',
                (int)($row['shown'] ?? 0),
                (int)($row['answered'] ?? 0),
                (int)($row['dropoff'] ?? 0),
                $this->formatPercent($row['dropoff_rate'] ?? 0),
                $this->formatPercent($row['answer_rate'] ?? 0),
            ]);
        }
        $this->putRow($handle, []);
    }

    private function writePopularAnswersSection($handle, array $rows): void
    {
        $this->putRow($handle, ['Секция', 'Квиз', 'Код квиза', 'Вопрос', 'Код вопроса', 'Ответ', 'Код ответа', 'Количество', 'Доля среди выбранных ответов']);

        foreach ($rows as $row) {
            $this->putRow($handle, [
                'Популярные ответы',
                $row['quiz_name'] ?? '',
                $row['quiz_code'] ?? '',
                $row['question_title'] ?? '',
                $row['question_code'] ?? '',
                $row['answer_title'] ?? '',
                $row['answer_code'] ?? '',
                (int)($row['count'] ?? 0),
                $this->formatPercent($row['share'] ?? 0),
            ]);
        }
        $this->putRow($handle, []);
    }

    private function writeLeadsByQuizSection($handle, array $rows): void
    {
        $this->putRow($handle, ['Секция', 'Квиз', 'Код квиза', 'Заявок']);

        foreach ($rows as $row) {
            $this->putRow($handle, [
                'Заявки по квизам',
                $row['quiz_name'] ?? '',
                $row['quiz_code'] ?? '',
                (int)($row['count'] ?? 0),
            ]);
        }
        $this->putRow($handle, []);
    }

    private function writeLeadsByResultSection($handle, array $rows): void
    {
        $this->putRow($handle, ['Секция', 'Квиз', 'Код квиза', 'Результат', 'Код результата', 'Заявок']);

        foreach ($rows as $row) {
            $this->putRow($handle, [
                'Заявки по результатам',
                $row['quiz_name'] ?? '',
                $row['quiz_code'] ?? '',
                $row['result_title'] ?? '',
                $row['result_code'] ?? '',
                (int)($row['count'] ?? 0),
            ]);
        }
    }

    private function putRow($handle, array $row): void
    {
        fputcsv($handle, array_map([$this, 'escapeCsvValue'], $row), ';');
    }

    private function escapeCsvValue(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    private function formatPercent(mixed $value): string
    {
        return number_format((float)$value, 1, '.', '') . '%';
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function buildFilename(?string $dateFrom, ?string $dateTo): string
    {
        if ($dateFrom !== null && $dateTo !== null) {
            return 'kk-quiz-statistics-' . $dateFrom . '--' . $dateTo . '.csv';
        }

        return 'kk-quiz-statistics-' . date('Y-m-d') . '.csv';
    }
}
