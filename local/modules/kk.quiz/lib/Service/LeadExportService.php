<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

final class LeadExportService
{
    private const LIMIT = 5000;

    public function exportCsv(array $filter = []): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('LEADS_IBLOCK_NOT_FOUND');
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            throw new \RuntimeException('LEADS_IBLOCK_NOT_FOUND');
        }

        $rows = [];
        $rows[] = [
            'ID',
            'Дата',
            'Квиз',
            'Код квиза',
            'Результат',
            'Имя',
            'Телефон',
            'Email',
            'Мессенджер',
            'Комментарий',
            'Ответы',
            'Страница',
            'UTM',
            'User Agent',
        ];

        $count = 0;
        $limited = false;
        $elements = \CIBlockElement::GetList(
            ['DATE_CREATE' => 'DESC', 'ID' => 'DESC'],
            array_merge(['IBLOCK_ID' => $iblockId], $filter),
            false,
            ['nTopCount' => self::LIMIT + 1],
            ['ID', 'IBLOCK_ID', 'NAME', 'DATE_CREATE', 'DETAIL_TEXT']
        );

        while ($elementObject = $elements->GetNextElement()) {
            if ($count >= self::LIMIT) {
                $limited = true;
                break;
            }

            $element = $elementObject->GetFields();
            $properties = $elementObject->GetProperties();
            $rows[] = $this->buildRow($element, is_array($properties) ? $properties : []);
            $count++;
        }

        if ($limited) {
            $rows[] = ['Экспорт ограничен первыми 5000 заявками'];
        }

        return [
            'filename' => 'kk-quiz-leads-' . date('Y-m-d') . '.csv',
            'content' => $this->buildCsv($rows),
        ];
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

    private function buildRow(array $element, array $properties): array
    {
        return [
            $element['ID'] ?? '',
            $element['DATE_CREATE'] ?? '',
            $this->getPropertyValue($properties, 'KK_LEAD_QUIZ_NAME'),
            $this->getPropertyValue($properties, 'KK_LEAD_QUIZ_CODE'),
            $this->getPropertyValue($properties, 'KK_LEAD_RESULT_TITLE'),
            $this->getPropertyValue($properties, 'KK_LEAD_CLIENT_NAME'),
            $this->getPropertyValue($properties, 'KK_LEAD_CLIENT_PHONE'),
            $this->getPropertyValue($properties, 'KK_LEAD_CLIENT_EMAIL'),
            $this->getPropertyValue($properties, 'KK_LEAD_CLIENT_MESSENGER'),
            $this->getPropertyValue($properties, 'KK_LEAD_CLIENT_COMMENT'),
            $this->buildAnswersText((string)($element['DETAIL_TEXT'] ?? ''), $this->getPropertyValue($properties, 'KK_LEAD_ANSWERS_DATA')),
            $this->getPropertyValue($properties, 'KK_LEAD_PAGE_URL'),
            $this->buildUtmText($properties),
            $this->getPropertyValue($properties, 'KK_LEAD_USER_AGENT'),
        ];
    }

    private function buildAnswersText(string $detailText, mixed $answersData): string
    {
        $detailText = trim($detailText);
        if ($detailText !== '') {
            return $detailText;
        }

        if (!is_string($answersData) || trim($answersData) === '') {
            return '';
        }

        $decoded = json_decode($answersData, true);
        if (!is_array($decoded)) {
            return $answersData;
        }

        $lines = [];
        foreach ($decoded as $question => $answer) {
            if (is_array($answer)) {
                $answer = implode(', ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE), $answer));
            }
            $lines[] = (string)$question . ': ' . (is_scalar($answer) ? (string)$answer : json_encode($answer, JSON_UNESCAPED_UNICODE));
        }

        return implode("\n", $lines);
    }

    private function buildUtmText(array $properties): string
    {
        $labels = [
            'KK_LEAD_UTM_SOURCE' => 'utm_source',
            'KK_LEAD_UTM_MEDIUM' => 'utm_medium',
            'KK_LEAD_UTM_CAMPAIGN' => 'utm_campaign',
            'KK_LEAD_UTM_CONTENT' => 'utm_content',
            'KK_LEAD_UTM_TERM' => 'utm_term',
        ];

        $lines = [];
        foreach ($labels as $propertyCode => $label) {
            $value = trim((string)$this->getPropertyValue($properties, $propertyCode));
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }

    private function getPropertyValue(array $properties, string $code): mixed
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return '';
        }

        $value = $properties[$code]['VALUE'] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string)$item : '', $value));
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function buildCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('CSV_CREATE_FAILED');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (mixed $value): string => $this->escapeCsvValue($value), $row), ';');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return is_string($content) ? $content : '';
    }

    private function escapeCsvValue(mixed $value): string
    {
        $value = trim((string)$value);

        if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }
}
