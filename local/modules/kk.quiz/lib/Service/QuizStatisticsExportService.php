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
        $quizCode = $this->normalizeQuizCode($options['quiz_code'] ?? '');
        $quizLabel = trim((string)($options['quiz_label'] ?? ''));

        $summary = (new QuizStatisticsService())->getSummary([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'quiz_code' => $quizCode,
        ]);
        $funnelSummary = (new QuizFunnelStatisticsService())->getSummary([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'quiz_code' => $quizCode,
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
        $this->putRow($handle, ['Квиз', $this->buildQuizLabel($quizCode, $quizLabel)]);
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
            'filename' => $this->buildFilename($dateFrom, $dateTo, $quizCode),
            'content' => $content,
        ];
    }


    public function exportHtmlXls(array $options = []): array
    {
        $dateFrom = $this->normalizeDate($options['date_from'] ?? null);
        $dateTo = $this->normalizeDate($options['date_to'] ?? null);
        $periodLabel = trim((string)($options['period_label'] ?? ''));
        $quizCode = $this->normalizeQuizCode($options['quiz_code'] ?? '');
        $quizLabel = trim((string)($options['quiz_label'] ?? ''));

        $summary = (new QuizStatisticsService())->getSummary([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'quiz_code' => $quizCode,
        ]);
        $funnelSummary = (new QuizFunnelStatisticsService())->getSummary([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'quiz_code' => $quizCode,
        ]);

        if ($periodLabel === '') {
            $periodLabel = (string)($summary['period']['label'] ?? '');
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . '<style>' . $this->getHtmlXlsStyles() . '</style>'
            . '</head><body>';
        $html .= '<h1>Статистика KK Quiz</h1>';
        $html .= '<div class="report-meta">Период: ' . $this->escapeHtml($periodLabel !== '' ? $periodLabel : 'всё время')
            . '<br>Квиз: ' . $this->escapeHtml($this->escapeSpreadsheetValue($this->buildQuizLabel($quizCode, $quizLabel)))
            . '<br>Дата экспорта: ' . $this->escapeHtml(date('d.m.Y H:i:s')) . '</div>';

        $html .= $this->renderSummaryTable($funnelSummary);
        $html .= $this->renderFunnelTable((array)($funnelSummary['funnel_by_quiz'] ?? []));
        $html .= $this->renderDropoffTable((array)($funnelSummary['question_dropoff'] ?? []));
        $html .= $this->renderPopularAnswersTable((array)($funnelSummary['popular_answers'] ?? []));
        $html .= $this->renderLeadsByQuizTable((array)($summary['by_quiz'] ?? []));
        $html .= $this->renderLeadsByResultTable((array)($summary['by_result'] ?? []));
        $html .= '</body></html>';

        return [
            'filename' => $this->buildHtmlXlsFilename($dateFrom, $dateTo, $quizCode),
            'content' => $html,
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


    private function renderSummaryTable(array $funnelSummary): string
    {
        $cards = is_array($funnelSummary['summary_cards'] ?? null) ? $funnelSummary['summary_cards'] : [];

        return $this->renderSectionTitle('Сводка')
            . '<table class="summary-table"><tr><th>Показатель</th><th>Значение</th></tr>'
            . $this->renderHtmlRow(['Показов квиза', (int)($cards['views'] ?? 0)], ['', 'number'])
            . $this->renderHtmlRow(['Начали прохождение', (int)($cards['starts'] ?? 0)], ['', 'number'])
            . $this->renderHtmlRow(['Дошли до результата', (int)($cards['results'] ?? 0)], ['', 'number'])
            . $this->renderHtmlRow(['Увидели форму', (int)($cards['forms'] ?? 0)], ['', 'number'])
            . $this->renderHtmlRow(['Отправили заявку', (int)($cards['leads'] ?? 0)], ['', 'number'])
            . $this->renderHtmlRow(['View → Lead', $this->formatPercent($cards['view_to_lead'] ?? 0)], ['', $this->getPercentClass($cards['view_to_lead'] ?? 0)])
            . $this->renderHtmlRow(['Start → Lead', $this->formatPercent($cards['start_to_lead'] ?? 0)], ['', $this->getPercentClass($cards['start_to_lead'] ?? 0)])
            . '</table>';
    }

    private function renderFunnelTable(array $rows): string
    {
        $html = $this->renderSectionTitle('Воронка по квизам')
            . $this->openHtmlTable(['Квиз', 'Код квиза', 'Показы', 'Открытия', 'Старты', 'Результаты', 'Формы', 'Заявки', 'View → Start', 'Start → Result', 'Result → Form', 'Form → Lead', 'View → Lead', 'Start → Lead']);

        foreach ($rows as $row) {
            $html .= $this->renderHtmlRow([
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
            ], ['', '', 'number', 'number', 'number', 'number', 'number', 'number', $this->getPercentClass($row['view_to_start'] ?? 0), $this->getPercentClass($row['start_to_result'] ?? 0), $this->getPercentClass($row['result_to_form'] ?? 0), $this->getPercentClass($row['form_to_lead'] ?? 0), $this->getPercentClass($row['view_to_lead'] ?? 0), $this->getPercentClass($row['start_to_lead'] ?? 0)]);
        }

        return $html . '</table>';
    }

    private function renderDropoffTable(array $rows): string
    {
        $html = $this->renderSectionTitle('Отвалы по вопросам')
            . $this->openHtmlTable(['Квиз', 'Код квиза', 'Шаг', 'Вопрос', 'Код вопроса', 'Показан', 'Ответили', 'Отвал', 'Отвал %', 'Доля ответа %']);

        foreach ($rows as $row) {
            $dropoffRate = (float)($row['dropoff_rate'] ?? 0);
            $html .= $this->renderHtmlRow([
                $row['quiz_name'] ?? '',
                $row['quiz_code'] ?? '',
                (int)($row['step_index'] ?? 0),
                $row['question_title'] ?? '',
                $row['question_code'] ?? '',
                (int)($row['shown'] ?? 0),
                (int)($row['answered'] ?? 0),
                (int)($row['dropoff'] ?? 0),
                $this->formatPercent($dropoffRate),
                $this->formatPercent($row['answer_rate'] ?? 0),
            ], ['', '', 'number', '', '', 'number', 'number', 'number', $dropoffRate >= 50.0 ? 'percent-bad' : ($dropoffRate >= 25.0 ? 'warning' : ''), $this->getPercentClass($row['answer_rate'] ?? 0)]);
        }

        return $html . '</table>';
    }

    private function renderPopularAnswersTable(array $rows): string
    {
        $html = $this->renderSectionTitle('Популярные ответы')
            . '<div class="report-note">Для checkbox-вопросов доля считается среди выбранных вариантов.</div>'
            . $this->openHtmlTable(['Квиз', 'Код квиза', 'Вопрос', 'Код вопроса', 'Ответ', 'Код ответа', 'Количество', 'Доля среди выбранных ответов']);

        foreach ($rows as $row) {
            $html .= $this->renderHtmlRow([
                $row['quiz_name'] ?? '',
                $row['quiz_code'] ?? '',
                $row['question_title'] ?? '',
                $row['question_code'] ?? '',
                $row['answer_title'] ?? '',
                $row['answer_code'] ?? '',
                (int)($row['count'] ?? 0),
                $this->formatPercent($row['share'] ?? 0),
            ], ['', '', '', '', '', '', 'number', $this->getPercentClass($row['share'] ?? 0)]);
        }

        return $html . '</table>';
    }

    private function renderLeadsByQuizTable(array $rows): string
    {
        $html = $this->renderSectionTitle('Заявки по квизам') . $this->openHtmlTable(['Квиз', 'Код квиза', 'Заявок']);
        foreach ($rows as $row) {
            $html .= $this->renderHtmlRow([$row['quiz_name'] ?? '', $row['quiz_code'] ?? '', (int)($row['count'] ?? 0)], ['', '', 'number']);
        }

        return $html . '</table>';
    }

    private function renderLeadsByResultTable(array $rows): string
    {
        $html = $this->renderSectionTitle('Заявки по результатам') . $this->openHtmlTable(['Квиз', 'Код квиза', 'Результат', 'Код результата', 'Заявок']);
        foreach ($rows as $row) {
            $html .= $this->renderHtmlRow([$row['quiz_name'] ?? '', $row['quiz_code'] ?? '', $row['result_title'] ?? '', $row['result_code'] ?? '', (int)($row['count'] ?? 0)], ['', '', '', '', 'number']);
        }

        return $html . '</table>';
    }

    private function renderSectionTitle(string $title): string
    {
        return '<div class="section-title">' . $this->escapeHtml($title) . '</div>';
    }

    private function openHtmlTable(array $headers): string
    {
        $html = '<table><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . $this->escapeHtml($header) . '</th>';
        }

        return $html . '</tr>';
    }

    private function renderHtmlRow(array $values, array $classes = []): string
    {
        $html = '<tr>';
        foreach ($values as $index => $value) {
            $class = trim((string)($classes[$index] ?? ''));
            $html .= '<td' . ($class !== '' ? ' class="' . $this->escapeHtml($class) . '"' : '') . '>' . $this->escapeHtml($this->escapeSpreadsheetValue($value)) . '</td>';
        }

        return $html . '</tr>';
    }

    private function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function getPercentClass(mixed $value): string
    {
        return (float)$value >= 50.0 ? 'percent-good' : 'percent-bad';
    }

    private function getHtmlXlsStyles(): string
    {
        return 'body{font-family:Arial,sans-serif;font-size:12px;color:#222;}h1{font-size:22px;margin-bottom:4px;}.report-meta{color:#666;margin-bottom:18px;}.report-note{color:#666;margin:6px 0 10px;}.summary-table td{font-size:14px;padding:8px 12px;}.section-title{background:#2f3a4a;color:#fff;font-size:15px;font-weight:bold;padding:8px 10px;margin-top:14px;}table{border-collapse:collapse;width:100%;margin-bottom:18px;}th{background:#eef2f7;font-weight:bold;}th,td{border:1px solid #cfd6df;padding:6px 8px;vertical-align:top;}.number{text-align:right;}.percent-good{color:#167a3a;font-weight:bold;}.percent-bad{color:#a62323;font-weight:bold;}.warning{background:#fff8d7;color:#8a5a00;font-weight:bold;}';
    }

    private function putRow($handle, array $row): void
    {
        fputcsv($handle, array_map([$this, 'escapeCsvValue'], $row), ';');
    }

    private function escapeCsvValue(mixed $value): string
    {
        return $this->escapeSpreadsheetValue($value);
    }

    private function escapeSpreadsheetValue(mixed $value): string
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

    private function normalizeQuizCode(mixed $value): string
    {
        $value = trim((string)$value);

        return preg_match('/^[a-zA-Z0-9_-]+$/', $value) === 1 ? $value : '';
    }

    private function buildQuizLabel(string $quizCode, string $quizLabel): string
    {
        if ($quizCode === '') {
            return 'Все квизы';
        }

        return $quizLabel !== '' ? $quizLabel : $quizCode;
    }

    private function buildFilename(?string $dateFrom, ?string $dateTo, string $quizCode = ''): string
    {
        $prefix = 'kk-quiz-statistics-' . ($quizCode !== '' ? $quizCode . '-' : '');
        if ($dateFrom !== null && $dateTo !== null) {
            return $prefix . $dateFrom . '--' . $dateTo . '.csv';
        }

        return $prefix . date('Y-m-d') . '.csv';
    }

    private function buildHtmlXlsFilename(?string $dateFrom, ?string $dateTo, string $quizCode = ''): string
    {
        $prefix = 'kk-quiz-statistics-report-' . ($quizCode !== '' ? $quizCode . '-' : '');
        if ($dateFrom !== null && $dateTo !== null) {
            return $prefix . $dateFrom . '--' . $dateTo . '.xls';
        }

        return $prefix . date('Y-m-d') . '.xls';
    }
}
