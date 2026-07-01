<?php

declare(strict_types=1);

namespace Kk\Quiz\Repository;

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

final class QuizRepository
{
    public function getQuizByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || !Loader::includeModule('iblock')) {
            return null;
        }

        $iblockId = $this->getQuizIblockId();
        if ($iblockId === null) {
            return null;
        }

        $section = $this->getSection($iblockId, ['CODE' => $code]);
        if ($section === null) {
            return null;
        }

        return $this->buildQuiz($iblockId, $section);
    }

    public function getQuizById(int $sectionId): ?array
    {
        if ($sectionId <= 0 || !Loader::includeModule('iblock')) {
            return null;
        }

        $iblockId = $this->getQuizIblockId();
        if ($iblockId === null) {
            return null;
        }

        $section = $this->getSection($iblockId, ['ID' => $sectionId]);
        if ($section === null) {
            return null;
        }

        return $this->buildQuiz($iblockId, $section);
    }

    private function getQuizIblockId(): ?int
    {
        $iblock = \CIBlock::GetList(
            [],
            [
                'TYPE' => Installer::IBLOCK_TYPE_ID,
                'CODE' => Installer::QUIZZES_IBLOCK_CODE,
                'ACTIVE' => 'Y',
            ]
        )->Fetch();

        return is_array($iblock) ? (int)$iblock['ID'] : null;
    }

    private function getSection(int $iblockId, array $filter): ?array
    {
        $sections = \CIBlockSection::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            array_merge(
                [
                    'IBLOCK_ID' => $iblockId,
                    'ACTIVE' => 'Y',
                ],
                $filter
            ),
            false,
            [
                'ID',
                'IBLOCK_ID',
                'CODE',
                'NAME',
                'DESCRIPTION',
                'SORT',
                'UF_KK_TITLE',
                'UF_KK_SUBTITLE',
                'UF_KK_BUTTON_TEXT',
                'UF_KK_FORM_BUTTON_TEXT',
                'UF_KK_START_TEXT',
                'UF_KK_SUCCESS_TEXT',
                'UF_KK_EMAIL_TO',
                'UF_KK_FORM_FIELDS',
                'UF_KK_REQUIRED_FIELDS',
                'UF_KK_METRIKA_COUNTER_ID',
                'UF_KK_METRIKA_GOAL',
                'UF_KK_USE_METRIKA',
                'UF_KK_USE_CATALOG',
                'UF_KK_CATALOG_IBLOCK_ID',
                'UF_KK_THEME',
                'UF_KK_ALLOW_POPUP_URL',
                'UF_KK_PRIVACY_TEXT',
                'UF_KK_PRIVACY_URL',
                'UF_KK_REQUIRE_AGREEMENT',
            ]
        );
        $section = $sections->Fetch();

        return is_array($section) ? $section : null;
    }

    private function buildQuiz(int $iblockId, array $section): array
    {
        $items = $this->getSectionItems($iblockId, (int)$section['ID']);

        return [
            'id' => (int)$section['ID'],
            'code' => (string)$section['CODE'],
            'name' => (string)$section['NAME'],
            'description' => (string)($section['DESCRIPTION'] ?? ''),
            'sort' => (int)$section['SORT'],
            'title' => (string)($section['UF_KK_TITLE'] ?? ''),
            'subtitle' => (string)($section['UF_KK_SUBTITLE'] ?? ''),
            'button_text' => (string)($section['UF_KK_BUTTON_TEXT'] ?? ''),
            'form_button_text' => (string)($section['UF_KK_FORM_BUTTON_TEXT'] ?? ''),
            'start_text' => (string)($section['UF_KK_START_TEXT'] ?? ''),
            'success_text' => (string)($section['UF_KK_SUCCESS_TEXT'] ?? ''),
            'email_to' => (string)($section['UF_KK_EMAIL_TO'] ?? ''),
            'form_fields' => $this->normalizeUserFieldEnumList($section['UF_KK_FORM_FIELDS'] ?? []),
            'required_fields' => $this->normalizeUserFieldEnumList($section['UF_KK_REQUIRED_FIELDS'] ?? []),
            'metrika_counter_id' => (string)($section['UF_KK_METRIKA_COUNTER_ID'] ?? ''),
            'metrika_goal' => $this->normalizeMetrikaGoal($section['UF_KK_METRIKA_GOAL'] ?? ''),
            'use_metrika' => $this->toBool($section['UF_KK_USE_METRIKA'] ?? null),
            'use_catalog' => $this->toBool($section['UF_KK_USE_CATALOG'] ?? null),
            'catalog_iblock_id' => $this->toNullableInt($section['UF_KK_CATALOG_IBLOCK_ID'] ?? null),
            'theme' => $this->normalizeUserFieldEnumValue($section['UF_KK_THEME'] ?? 'default') ?: 'default',
            'allow_popup_url' => $this->toBool($section['UF_KK_ALLOW_POPUP_URL'] ?? null),
            'privacy_text' => (string)($section['UF_KK_PRIVACY_TEXT'] ?? ''),
            'privacy_url' => (string)($section['UF_KK_PRIVACY_URL'] ?? ''),
            'require_agreement' => $this->toBool($section['UF_KK_REQUIRE_AGREEMENT'] ?? null),
            'questions' => $items['questions'],
            'results' => $items['results'],
        ];
    }

    private function getSectionItems(int $iblockId, int $sectionId): array
    {
        $items = [
            'questions' => [],
            'results' => [],
        ];

        $elements = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
                'SECTION_ID' => $sectionId,
                'ACTIVE' => 'Y',
                'INCLUDE_SUBSECTIONS' => 'N',
            ],
            false,
            false,
            [
                'ID',
                'IBLOCK_ID',
                'CODE',
                'NAME',
                'SORT',
                'PREVIEW_TEXT',
                'DETAIL_TEXT',
                'PREVIEW_PICTURE',
                'DETAIL_PICTURE',
            ]
        );

        while ($elementObject = $elements->GetNextElement()) {
            $element = $elementObject->GetFields();
            $properties = $elementObject->GetProperties();
            $entityType = strtoupper($this->getElementPropertyEnumXmlId($properties, 'KK_ENTITY_TYPE'));

            if ($entityType === '') {
                $entityType = strtoupper((string)$this->getElementPropertyValue($properties, 'KK_ENTITY_TYPE'));
            }

            if ($entityType === 'QUESTION') {
                $items['questions'][] = $this->mapQuestion($element, $properties);
            }

            if ($entityType === 'RESULT') {
                $items['results'][] = $this->mapResult($element, $properties);
            }
        }

        return $items;
    }

    private function mapQuestion(array $element, array $properties): array
    {
        return [
            'id' => (int)$element['ID'],
            'code' => (string)($element['CODE'] ?? ''),
            'name' => (string)($element['NAME'] ?? ''),
            'hint' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'sort' => (int)($element['SORT'] ?? 0),
            'question_type' => $this->getElementPropertyEnumXmlId($properties, 'KK_QUESTION_TYPE'),
            'display_template' => $this->getElementPropertyEnumXmlId($properties, 'KK_DISPLAY_TEMPLATE'),
            'is_required' => $this->toBool($this->getElementPropertyEnumXmlId($properties, 'KK_IS_REQUIRED')),
            'placeholder' => (string)$this->getElementPropertyValue($properties, 'KK_PLACEHOLDER'),
            'default_next_question_id' => $this->toNullableInt($this->getElementPropertyValue($properties, 'KK_DEFAULT_NEXT_QUESTION')),
            'answers' => $this->normalizeAnswers($this->getElementPropertyValue($properties, 'KK_ANSWERS')),
        ];
    }

    private function mapResult(array $element, array $properties): array
    {
        $pictureId = $this->toNullableInt($element['PREVIEW_PICTURE'] ?? null) ?? $this->toNullableInt($element['DETAIL_PICTURE'] ?? null);

        return [
            'id' => (int)$element['ID'],
            'code' => (string)($element['CODE'] ?? ''),
            'name' => (string)($element['NAME'] ?? ''),
            'preview_text' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'detail_text' => (string)($element['DETAIL_TEXT'] ?? ''),
            'picture_id' => $pictureId,
            'picture_src' => $this->getFilePath($pictureId),
            'sort' => (int)($element['SORT'] ?? 0),
            'min_score' => (int)$this->getElementPropertyValue($properties, 'KK_RESULT_MIN_SCORE'),
            'max_score' => (int)$this->getElementPropertyValue($properties, 'KK_RESULT_MAX_SCORE'),
            'priority' => (int)$this->getElementPropertyValue($properties, 'KK_RESULT_PRIORITY'),
            'cta_text' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_CTA_TEXT'),
            'cta_link' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_CTA_LINK'),
            'show_form' => $this->toBool($this->getElementPropertyEnumXmlId($properties, 'KK_RESULT_SHOW_FORM')),
            'catalog_section_id' => $this->toNullableInt($this->getElementPropertyValue($properties, 'KK_RESULT_CATALOG_SECTION')),
            'catalog_product_ids' => $this->normalizeIntList($this->getElementPropertyValues($properties, 'KK_RESULT_CATALOG_PRODUCTS')),
            'badge' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_BADGE'),
        ];
    }

    private function getElementPropertyValue(array $properties, string $code): mixed
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return null;
        }

        return $properties[$code]['VALUE'] ?? null;
    }

    private function getElementPropertyValues(array $properties, string $code): array
    {
        $value = $this->getElementPropertyValue($properties, $code);

        return is_array($value) ? $value : [$value];
    }

    private function getElementPropertyEnumXmlId(array $properties, string $code): string
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return '';
        }

        $property = $properties[$code];
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

        $enumXmlId = $this->getPropertyEnumXmlId($this->toNullableInt($enumId));
        if ($enumXmlId !== '') {
            return $enumXmlId;
        }

        $value = $property['VALUE'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private function getElementPropertyEnumXmlIds(array $properties, string $code): array
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return [];
        }

        $property = $properties[$code];
        $xmlIds = $property['VALUE_XML_ID'] ?? [];
        $xmlIds = is_array($xmlIds) ? $xmlIds : [$xmlIds];
        $enumIds = $property['VALUE_ENUM_ID'] ?? [];
        $enumIds = is_array($enumIds) ? $enumIds : [$enumIds];
        $values = $property['VALUE'] ?? [];
        $values = is_array($values) ? $values : [$values];
        $result = [];

        foreach ($values as $index => $value) {
            $xmlId = (string)($xmlIds[$index] ?? '');
            if ($xmlId === '') {
                $xmlId = $this->getPropertyEnumXmlId($this->toNullableInt($enumIds[$index] ?? null));
            }
            if ($xmlId === '' && is_scalar($value)) {
                $xmlId = (string)$value;
            }
            if ($xmlId !== '' && !in_array($xmlId, $result, true)) {
                $result[] = $xmlId;
            }
        }

        return $result;
    }

    private function getPropertyEnumXmlId(?int $enumId): string
    {
        if ($enumId === null) {
            return '';
        }

        $enum = \CIBlockPropertyEnum::GetByID($enumId);
        if (!is_array($enum)) {
            return '';
        }

        $xmlId = (string)($enum['XML_ID'] ?? '');

        return $xmlId !== '' ? $xmlId : (string)($enum['VALUE'] ?? '');
    }


    private function normalizeMetrikaGoal(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        if ($value === '') {
            return '';
        }

        return preg_match('/^[a-zA-Z0-9_-]+$/', $value) === 1 ? $value : '';
    }

    private function normalizeAnswers(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $answers = [];
        foreach ($value as $answer) {
            if (!is_array($answer) || (string)($answer['active'] ?? 'N') !== 'Y') {
                continue;
            }

            $imageId = $this->toNullableInt($answer['image_id'] ?? null);
            $answers[] = [
                'active' => 'Y',
                'sort' => (int)($answer['sort'] ?? 100),
                'text' => (string)($answer['text'] ?? ''),
                'code' => (string)($answer['code'] ?? ''),
                'image_id' => $imageId,
                'image_src' => $this->getFilePath($imageId),
                'description' => (string)($answer['description'] ?? ''),
                'next_question_id' => $this->toNullableInt($answer['next_question_id'] ?? null),
                'result_id' => $this->toNullableInt($answer['result_id'] ?? null),
                'score_result_id' => $this->toNullableInt($answer['score_result_id'] ?? null),
                'score_value' => (int)($answer['score_value'] ?? 0),
            ];
        }

        usort($answers, static fn (array $left, array $right): int => ($left['sort'] <=> $right['sort']));

        return $answers;
    }

    private function normalizeUserFieldEnumList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];

        foreach ($values as $item) {
            $xmlId = $this->normalizeUserFieldEnumValue($item);
            if ($xmlId !== '' && !in_array($xmlId, $result, true)) {
                $result[] = $xmlId;
            }
        }

        return $result;
    }

    private function normalizeUserFieldEnumValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === false || $value === '') {
            return '';
        }

        if (is_numeric($value) && class_exists('CUserFieldEnum')) {
            $enum = \CUserFieldEnum::GetList([], ['ID' => (int)$value])->Fetch();
            if (is_array($enum)) {
                $xmlId = (string)($enum['XML_ID'] ?? '');

                return $xmlId !== '' ? $xmlId : (string)($enum['VALUE'] ?? '');
            }
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private function normalizeIntList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $item) {
            $item = (int)$item;
            if ($item > 0 && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
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

    private function getFilePath(?int $fileId): ?string
    {
        if ($fileId === null || !class_exists('CFile')) {
            return null;
        }

        $path = \CFile::GetPath($fileId);

        return is_string($path) && $path !== '' ? $path : null;
    }
}
