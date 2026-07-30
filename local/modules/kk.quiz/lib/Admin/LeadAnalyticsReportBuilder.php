<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

final class LeadAnalyticsReportBuilder
{
    private const STATUSES = [
        'new', 'in_progress', 'contacted', 'deal_created', 'closed', 'rejected', 'spam', 'done', 'empty', 'unknown',
    ];

    public function build(array $filter): array
    {
        $report = $this->emptyReport();
        $quizzes = [];
        $results = [];
        $utmSources = [];
        $utmCampaigns = [];
        $daily = [];

        $elements = \CIBlockElement::GetList(
            ['DATE_CREATE' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'IBLOCK_ID', 'DATE_CREATE']
        );

        while ($element = $elements->GetNextElement()) {
            $fields = $element->GetFields();
            $properties = $element->GetProperties();
            $dateCreate = $fields['DATE_CREATE'] ?? '';
            $statusValue = $this->property($properties, 'KK_LEAD_STATUS');
            $statusXmlId = LeadStatusHelper::normalizeXmlId(
                $this->property($properties, 'KK_LEAD_STATUS', true),
                $statusValue
            );
            if ($statusXmlId === '') {
                $statusXmlId = 'empty';
            } elseif (!LeadStatusHelper::isKnownStatus($statusXmlId)) {
                $statusXmlId = 'unknown';
            }

            ++$report['total'];
            ++$report['statuses'][$statusXmlId];
            if (LeadAttentionHelper::requiresAttention($statusXmlId, $dateCreate)) {
                ++$report['attention'];
            }

            $this->incrementNamedGroup(
                $quizzes,
                $this->property($properties, 'KK_LEAD_QUIZ_CODE'),
                $this->property($properties, 'KK_LEAD_QUIZ_NAME'),
                'name'
            );
            $this->incrementNamedGroup(
                $results,
                $this->property($properties, 'KK_LEAD_RESULT_CODE'),
                $this->property($properties, 'KK_LEAD_RESULT_TITLE'),
                'title'
            );
            $this->incrementValueGroup($utmSources, $this->property($properties, 'KK_LEAD_UTM_SOURCE'));
            $this->incrementValueGroup($utmCampaigns, $this->property($properties, 'KK_LEAD_UTM_CAMPAIGN'));

            $timestamp = LeadAttentionHelper::timestampFromBitrixDate($dateCreate);
            if ($timestamp > 0) {
                $date = date('Y-m-d', $timestamp);
                $daily[$date] = ($daily[$date] ?? 0) + 1;
            }
        }

        $total = $report['total'];
        foreach (['deal_created', 'closed', 'rejected'] as $status) {
            $report['conversion'][$status . '_percent'] = $total > 0
                ? round($report['statuses'][$status] / $total * 100, 1)
                : 0.0;
        }

        $report['top_quizzes'] = $this->top(array_values($quizzes));
        $report['top_results'] = $this->top(array_values($results));
        $report['top_utm_sources'] = $this->valueTop($utmSources);
        $report['top_utm_campaigns'] = $this->valueTop($utmCampaigns);
        ksort($daily);
        foreach ($daily as $date => $count) {
            $timestamp = strtotime($date . ' 00:00:00');
            $report['daily'][] = [
                'date' => $date,
                'label' => $timestamp !== false ? date('d.m', $timestamp) : $date,
                'count' => $count,
            ];
        }

        return $report;
    }

    private function emptyReport(): array
    {
        return [
            'total' => 0,
            'attention' => 0,
            'statuses' => array_fill_keys(self::STATUSES, 0),
            'conversion' => [
                'deal_created_percent' => 0.0,
                'closed_percent' => 0.0,
                'rejected_percent' => 0.0,
            ],
            'top_quizzes' => [],
            'top_results' => [],
            'top_utm_sources' => [],
            'top_utm_campaigns' => [],
            'daily' => [],
        ];
    }

    private function property(array $properties, string $code, bool $xmlId = false): string
    {
        $property = $properties[$code] ?? [];
        $value = $xmlId ? ($property['VALUE_XML_ID'] ?? '') : ($property['VALUE'] ?? '');
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function incrementNamedGroup(array &$groups, string $code, string $label, string $labelKey): void
    {
        $displayCode = $code !== '' ? $code : 'Без кода';
        $displayLabel = $label !== '' ? $label : ($code !== '' ? $code : 'Без кода');
        $key = $code . "\0" . $displayLabel;
        if (!isset($groups[$key])) {
            $groups[$key] = ['code' => $displayCode, $labelKey => $displayLabel, 'count' => 0];
        }
        ++$groups[$key]['count'];
    }

    private function incrementValueGroup(array &$groups, string $value): void
    {
        $value = $value !== '' ? $value : 'Без UTM';
        $groups[$value] = ($groups[$value] ?? 0) + 1;
    }

    private function top(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($items, 0, 10);
    }

    private function valueTop(array $groups): array
    {
        $items = [];
        foreach ($groups as $value => $count) {
            $items[] = ['value' => $value, 'count' => $count];
        }

        return $this->top($items);
    }
}
