<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

final class QuizExportService
{
    public function exportByCode(string $quizCode): ?array
    {
        $quizCode = trim($quizCode);
        if ($quizCode === '' || !Loader::includeModule('iblock')) {
            return null;
        }

        $iblock = \CIBlock::GetList(
            [],
            [
                'TYPE' => Installer::IBLOCK_TYPE_ID,
                'CODE' => Installer::QUIZZES_IBLOCK_CODE,
            ]
        )->Fetch();
        if (!is_array($iblock)) {
            return null;
        }

        $iblockId = (int)$iblock['ID'];
        $section = $this->getSection($iblockId, $quizCode);
        if ($section === null) {
            return null;
        }

        $items = $this->getSectionItems($iblockId, (int)$section['ID']);
        $questionCodeMap = $items['questionCodeMap'];
        $resultCodeMap = $items['resultCodeMap'];

        $questions = [];
        foreach ($items['questions'] as $item) {
            $questions[] = $this->mapQuestion($item['element'], $item['properties'], $questionCodeMap, $resultCodeMap);
        }

        $results = [];
        foreach ($items['results'] as $item) {
            $results[] = $this->mapResult($item['element'], $item['properties']);
        }

        $legacyCatalogIblockId = $this->toNullableInt($section['UF_KK_CATALOG_IBLOCK_ID'] ?? null);
        $catalogIblockIds = array_values(array_filter(
            array_map('intval', $this->normalizeStringList($section['UF_KK_CATALOG_IBLOCK_IDS'] ?? [])),
            static fn (int $id): bool => $id > 0
        ));
        if ($catalogIblockIds === [] && $legacyCatalogIblockId !== null) {
            $catalogIblockIds = [$legacyCatalogIblockId];
        }

        $startQuestionId = $this->toNullableInt($section['UF_KK_START_QUESTION'] ?? null);

        return [
            'schema' => 'kk.quiz.export.v1',
            'module' => 'kk.quiz',
            'version' => 1,
            'exported_at' => date(DATE_ATOM),
            'quiz' => [
                '_source_id' => (int)$section['ID'],
                'code' => (string)$section['CODE'],
                'name' => (string)$section['NAME'],
                'description' => (string)($section['DESCRIPTION'] ?? ''),
                'sort' => (int)($section['SORT'] ?? 500),
                'settings' => [
                    'title' => (string)($section['UF_KK_TITLE'] ?? ''),
                    'subtitle' => (string)($section['UF_KK_SUBTITLE'] ?? ''),
                    'button_text' => (string)($section['UF_KK_BUTTON_TEXT'] ?? ''),
                    'form_button_text' => (string)($section['UF_KK_FORM_BUTTON_TEXT'] ?? ''),
                    'form_title' => (string)($section['UF_KK_FORM_TITLE'] ?? ''),
                    'form_subtitle' => (string)($section['UF_KK_FORM_SUBTITLE'] ?? ''),
                    'start_text' => (string)($section['UF_KK_START_TEXT'] ?? ''),
                    'progress_total' => max(0, (int)($section['UF_KK_PROGRESS_TOTAL'] ?? 0)),
                    'success_text' => (string)($section['UF_KK_SUCCESS_TEXT'] ?? ''),
                    'email_to' => (string)($section['UF_KK_EMAIL_TO'] ?? ''),
                    'form_fields' => $this->normalizeStringList($section['UF_KK_FORM_FIELDS'] ?? []),
                    'required_fields' => $this->normalizeStringList($section['UF_KK_REQUIRED_FIELDS'] ?? []),
                    'metrika_counter_id' => (string)($section['UF_KK_METRIKA_COUNTER_ID'] ?? ''),
                    'metrika_goal' => (string)($section['UF_KK_METRIKA_GOAL'] ?? ''),
                    'use_metrika' => $this->toBool($section['UF_KK_USE_METRIKA'] ?? null),
                    'use_catalog' => $this->toBool($section['UF_KK_USE_CATALOG'] ?? null),
                    'catalog_iblock_id' => $legacyCatalogIblockId,
                    'catalog_iblock_ids' => $catalogIblockIds,
                    'theme' => $this->normalizeStringList($section['UF_KK_THEME'] ?? 'default')[0] ?? 'default',
                    'accent_color' => (string)($section['UF_KK_ACCENT_COLOR'] ?? ''),
                    'accent_hover_color' => (string)($section['UF_KK_ACCENT_HOVER'] ?? ''),
                    'border_radius' => ($section['UF_KK_BORDER_RADIUS'] ?? '') !== '' ? (int)$section['UF_KK_BORDER_RADIUS'] : null,
                    'answer_image_ratio' => $this->normalizeStringList($section['UF_KK_IMAGE_RATIO'] ?? '')[0] ?? '',
                    'answer_image_fit' => $this->normalizeStringList($section['UF_KK_IMAGE_FIT'] ?? '')[0] ?? '',
                    'allow_popup_url' => $this->toBool($section['UF_KK_ALLOW_POPUP_URL'] ?? null),
                    'privacy_text' => (string)($section['UF_KK_PRIVACY_TEXT'] ?? ''),
                    'privacy_url' => (string)($section['UF_KK_PRIVACY_URL'] ?? ''),
                    'require_agreement' => $this->toBool($section['UF_KK_REQUIRE_AGREEMENT'] ?? null),
                ],
                'start_question_code' => $startQuestionId !== null ? $this->getElementCodeById($startQuestionId, $questionCodeMap) : '',
            ],
            'questions' => $questions,
            'results' => $results,
        ];
    }

    private function getSection(int $iblockId, string $quizCode): ?array
    {
        $sections = \CIBlockSection::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $quizCode],
            false,
            [
                'ID', 'IBLOCK_ID', 'CODE', 'NAME', 'DESCRIPTION', 'SORT',
                'UF_KK_TITLE', 'UF_KK_SUBTITLE', 'UF_KK_BUTTON_TEXT', 'UF_KK_FORM_BUTTON_TEXT',
                'UF_KK_FORM_TITLE', 'UF_KK_FORM_SUBTITLE', 'UF_KK_START_TEXT', 'UF_KK_START_QUESTION',
                'UF_KK_PROGRESS_TOTAL',
                'UF_KK_SUCCESS_TEXT', 'UF_KK_EMAIL_TO', 'UF_KK_FORM_FIELDS', 'UF_KK_REQUIRED_FIELDS',
                'UF_KK_METRIKA_COUNTER_ID', 'UF_KK_METRIKA_GOAL', 'UF_KK_USE_METRIKA', 'UF_KK_USE_CATALOG',
                'UF_KK_CATALOG_IBLOCK_ID', 'UF_KK_CATALOG_IBLOCK_IDS', 'UF_KK_THEME',
                'UF_KK_ACCENT_COLOR', 'UF_KK_ACCENT_HOVER', 'UF_KK_BORDER_RADIUS', 'UF_KK_IMAGE_RATIO', 'UF_KK_IMAGE_FIT', 'UF_KK_ALLOW_POPUP_URL',
                'UF_KK_PRIVACY_TEXT', 'UF_KK_PRIVACY_URL', 'UF_KK_REQUIRE_AGREEMENT',
            ]
        );
        $section = $sections->Fetch();

        return is_array($section) ? $section : null;
    }

    private function getSectionItems(int $iblockId, int $sectionId): array
    {
        $items = ['questions' => [], 'results' => [], 'questionCodeMap' => [], 'resultCodeMap' => []];
        $elements = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'SECTION_ID' => $sectionId, 'INCLUDE_SUBSECTIONS' => 'N'],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'CODE', 'ACTIVE', 'NAME', 'SORT', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
        );

        while ($elementObject = $elements->GetNextElement()) {
            $element = $elementObject->GetFields();
            $properties = $elementObject->GetProperties();
            $entityType = strtoupper($this->getEnumXmlId($properties['KK_ENTITY_TYPE'] ?? []));
            if ($entityType === '') {
                $entityType = strtoupper((string)$this->getPropertyValue($properties, 'KK_ENTITY_TYPE'));
            }

            $id = (int)$element['ID'];
            $code = (string)($element['CODE'] ?? '');
            if ($entityType === 'QUESTION') {
                $items['questionCodeMap'][$id] = $code;
                $items['questions'][] = ['element' => $element, 'properties' => $properties];
            } elseif ($entityType === 'RESULT') {
                $items['resultCodeMap'][$id] = $code;
                $items['results'][] = ['element' => $element, 'properties' => $properties];
            }
        }

        return $items;
    }

    private function mapQuestion(array $element, array $properties, array $questionCodeMap, array $resultCodeMap): array
    {
        $defaultNextQuestionId = $this->toNullableInt($this->getPropertyValue($properties, 'KK_DEFAULT_NEXT_QUESTION'));
        $defaultResultId = $this->toNullableInt($this->getPropertyValue($properties, 'KK_DEFAULT_RESULT'));

        return [
            '_source_id' => (int)$element['ID'],
            'code' => (string)($element['CODE'] ?? ''),
            'active' => ($element['ACTIVE'] ?? 'N') === 'Y',
            'sort' => (int)($element['SORT'] ?? 0),
            'admin_name' => (string)($element['NAME'] ?? ''),
            'public_title' => (string)$this->getPropertyValue($properties, 'KK_PUBLIC_TITLE'),
            'preview_text' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'detail_text' => (string)($element['DETAIL_TEXT'] ?? ''),
            'question_type' => strtolower($this->getEnumXmlId($properties['KK_QUESTION_TYPE'] ?? [])) ?: 'radio',
            'display_template' => strtolower($this->getEnumXmlId($properties['KK_DISPLAY_TEMPLATE'] ?? [])) ?: 'list',
            'answer_image_ratio' => strtolower($this->getEnumXmlId($properties['KK_IMAGE_RATIO'] ?? [])),
            'is_required' => $this->toBool($this->getEnumXmlId($properties['KK_IS_REQUIRED'] ?? [])),
            'placeholder' => (string)$this->getPropertyValue($properties, 'KK_PLACEHOLDER'),
            'default_next_question_code' => $defaultNextQuestionId !== null ? $this->getElementCodeById($defaultNextQuestionId, $questionCodeMap) : '',
            'default_result_code' => $defaultResultId !== null ? $this->getElementCodeById($defaultResultId, $resultCodeMap) : '',
            'allow_custom_answer' => $this->toBool($this->getEnumXmlId($properties['KK_ALLOW_CUSTOM_ANSWER'] ?? [])),
            'answers' => $this->mapAnswers($this->getPropertyValue($properties, 'KK_ANSWERS'), $questionCodeMap, $resultCodeMap),
        ];
    }

    private function mapAnswers(mixed $value, array $questionCodeMap, array $resultCodeMap): array
    {
        $answers = [];
        foreach ($this->decodeAnswers($value) as $answer) {
            $imageId = $this->toNullableInt($answer['image_id'] ?? $answer['IMAGE_ID'] ?? null);
            $nextQuestionId = $this->toNullableInt($answer['next_question_id'] ?? $answer['NEXT_QUESTION_ID'] ?? null);
            $resultId = $this->toNullableInt($answer['result_id'] ?? $answer['RESULT_ID'] ?? null);
            $scoreResultId = $this->toNullableInt($answer['score_result_id'] ?? $answer['SCORE_RESULT_ID'] ?? null);

            $answers[] = [
                'active' => $this->toBool($answer['active'] ?? $answer['ACTIVE'] ?? 'N'),
                'sort' => (int)($answer['sort'] ?? $answer['SORT'] ?? 100),
                'text' => (string)($answer['text'] ?? $answer['TEXT'] ?? ''),
                'code' => (string)($answer['code'] ?? $answer['CODE'] ?? ''),
                'description' => (string)($answer['description'] ?? $answer['DESCRIPTION'] ?? ''),
                'image_id' => $imageId,
                'image_src' => $this->getFilePath($imageId),
                'next_question_code' => $nextQuestionId !== null ? $this->getElementCodeById($nextQuestionId, $questionCodeMap) : '',
                'result_code' => $resultId !== null ? $this->getElementCodeById($resultId, $resultCodeMap) : '',
                'score_result_code' => $scoreResultId !== null ? $this->getElementCodeById($scoreResultId, $resultCodeMap) : '',
                'score_value' => (int)($answer['score_value'] ?? $answer['SCORE_VALUE'] ?? 0),
                '_source_next_question_id' => $nextQuestionId,
                '_source_result_id' => $resultId,
                '_source_score_result_id' => $scoreResultId,
            ];
        }

        usort($answers, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return $answers;
    }

    private function mapResult(array $element, array $properties): array
    {
        return [
            '_source_id' => (int)$element['ID'],
            'code' => (string)($element['CODE'] ?? ''),
            'active' => ($element['ACTIVE'] ?? 'N') === 'Y',
            'sort' => (int)($element['SORT'] ?? 0),
            'admin_name' => (string)($element['NAME'] ?? ''),
            'public_title' => (string)$this->getPropertyValue($properties, 'KK_PUBLIC_TITLE'),
            'preview_text' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'summary' => (string)$this->getPropertyValue($properties, 'KK_RESULT_SUMMARY'),
            'why_text' => (string)$this->getPropertyValue($properties, 'KK_RESULT_WHY_TEXT'),
            'specs_text' => (string)$this->getPropertyValue($properties, 'KK_RESULT_SPECS_TEXT'),
            'note_text' => (string)$this->getPropertyValue($properties, 'KK_RESULT_NOTE_TEXT'),
            'form_title' => (string)$this->getPropertyValue($properties, 'KK_RESULT_FORM_TITLE'),
            'form_intro' => (string)$this->getPropertyValue($properties, 'KK_RESULT_FORM_INTRO'),
            'form_button_text' => (string)$this->getPropertyValue($properties, 'KK_RESULT_FORM_BUTTON_TEXT'),
            'detail_text' => (string)($element['DETAIL_TEXT'] ?? ''),
            'min_score' => $this->toNullableInt($this->getPropertyValue($properties, 'KK_RESULT_MIN_SCORE')),
            'max_score' => $this->toNullableInt($this->getPropertyValue($properties, 'KK_RESULT_MAX_SCORE')),
            'priority' => (int)$this->getPropertyValue($properties, 'KK_RESULT_PRIORITY'),
            'cta_text' => (string)$this->getPropertyValue($properties, 'KK_RESULT_CTA_TEXT'),
            'cta_link' => (string)$this->getPropertyValue($properties, 'KK_RESULT_CTA_LINK'),
            'video_url' => (string)$this->getPropertyValue($properties, 'KK_RESULT_VIDEO_URL'),
            'video_title' => (string)$this->getPropertyValue($properties, 'KK_RESULT_VIDEO_TITLE'),
            'video_position' => (string)$this->getEnumXmlId($properties['KK_RESULT_VIDEO_POSITION'] ?? []),
            'show_form' => $this->toBool($this->getEnumXmlId($properties['KK_RESULT_SHOW_FORM'] ?? [])),
            'catalog_section_id' => $this->toNullableInt($this->getPropertyValue($properties, 'KK_RESULT_CATALOG_SECTION')),
            'catalog_product_ids' => array_values(array_filter(array_map('intval', is_array($this->getPropertyValue($properties, 'KK_RESULT_CATALOG_PRODUCTS')) ? $this->getPropertyValue($properties, 'KK_RESULT_CATALOG_PRODUCTS') : [$this->getPropertyValue($properties, 'KK_RESULT_CATALOG_PRODUCTS')]), static fn (int $id): bool => $id > 0)),
            'badge' => (string)$this->getPropertyValue($properties, 'KK_RESULT_BADGE'),
        ];
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 'Y' || $value === '1' || $value === 1 || $value === 'Да';
    }

    private function toNullableInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private function normalizeStringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $item) {
            if (is_array($item)) {
                $item = reset($item);
            }
            if (is_numeric($item) && class_exists('CUserFieldEnum')) {
                $enum = \CUserFieldEnum::GetList([], ['ID' => (int)$item])->Fetch();
                if (is_array($enum)) {
                    $xmlId = (string)($enum['XML_ID'] ?? '');
                    $item = $xmlId !== '' ? $xmlId : (string)($enum['VALUE'] ?? '');
                }
            }
            $item = is_scalar($item) ? trim((string)$item) : '';
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function getEnumXmlId(array $property): string
    {
        $xmlId = $property['VALUE_XML_ID'] ?? null;
        if (is_array($xmlId)) {
            $xmlId = reset($xmlId);
        }
        if (is_string($xmlId) && $xmlId !== '') {
            return $xmlId;
        }

        $enumId = $property['VALUE_ENUM_ID'] ?? null;
        if (is_array($enumId)) {
            $enumId = reset($enumId);
        }
        if ($this->toNullableInt($enumId) !== null) {
            $enum = \CIBlockPropertyEnum::GetByID((int)$enumId);
            if (is_array($enum)) {
                $xmlId = (string)($enum['XML_ID'] ?? '');
                return $xmlId !== '' ? $xmlId : (string)($enum['VALUE'] ?? '');
            }
        }

        $value = $property['VALUE'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private function getPropertyValue(array $properties, string $code): mixed
    {
        return isset($properties[$code]) && is_array($properties[$code]) ? ($properties[$code]['VALUE'] ?? null) : null;
    }

    private function decodeAnswers(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $rows = $value['rows'] ?? $value;
        if (!is_array($rows)) {
            return [];
        }

        $answers = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $decoded = json_decode($row, true);
                $row = is_array($decoded) ? $decoded : ['text' => $row];
            }
            if (is_array($row)) {
                $answers[] = $row;
            }
        }

        return $answers;
    }

    private function getElementCodeById(int $id, array $codeMap): string
    {
        return isset($codeMap[$id]) ? (string)$codeMap[$id] : '';
    }

    private function getFilePath(?int $fileId): string
    {
        if ($fileId === null || !class_exists('CFile')) {
            return '';
        }

        $path = \CFile::GetPath($fileId);

        return is_string($path) ? $path : '';
    }
}
