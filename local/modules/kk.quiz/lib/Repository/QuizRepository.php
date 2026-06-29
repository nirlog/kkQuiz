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
                'UF_KK_START_TEXT',
                'UF_KK_SUCCESS_TEXT',
                'UF_KK_EMAIL_TO',
                'UF_KK_FORM_FIELDS',
                'UF_KK_REQUIRED_FIELDS',
                'UF_KK_METRIKA_COUNTER_ID',
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
            'start_text' => (string)($section['UF_KK_START_TEXT'] ?? ''),
            'success_text' => (string)($section['UF_KK_SUCCESS_TEXT'] ?? ''),
            'email_to' => (string)($section['UF_KK_EMAIL_TO'] ?? ''),
            'form_fields' => $this->normalizeStringList($section['UF_KK_FORM_FIELDS'] ?? []),
            'required_fields' => $this->normalizeStringList($section['UF_KK_REQUIRED_FIELDS'] ?? []),
            'metrika_counter_id' => (string)($section['UF_KK_METRIKA_COUNTER_ID'] ?? ''),
            'use_metrika' => $this->toBool($section['UF_KK_USE_METRIKA'] ?? null),
            'use_catalog' => $this->toBool($section['UF_KK_USE_CATALOG'] ?? null),
            'catalog_iblock_id' => $this->toNullableInt($section['UF_KK_CATALOG_IBLOCK_ID'] ?? null),
            'theme' => (string)($section['UF_KK_THEME'] ?? 'default'),
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
                'PROPERTY_KK_ENTITY_TYPE',
                'PROPERTY_KK_QUESTION_TYPE',
                'PROPERTY_KK_DISPLAY_TEMPLATE',
                'PROPERTY_KK_IS_REQUIRED',
                'PROPERTY_KK_PLACEHOLDER',
                'PROPERTY_KK_DEFAULT_NEXT_QUESTION',
                'PROPERTY_KK_ANSWERS',
                'PROPERTY_KK_RESULT_MIN_SCORE',
                'PROPERTY_KK_RESULT_MAX_SCORE',
                'PROPERTY_KK_RESULT_PRIORITY',
                'PROPERTY_KK_RESULT_CTA_TEXT',
                'PROPERTY_KK_RESULT_CTA_LINK',
                'PROPERTY_KK_RESULT_SHOW_FORM',
                'PROPERTY_KK_RESULT_CATALOG_SECTION',
                'PROPERTY_KK_RESULT_CATALOG_PRODUCTS',
                'PROPERTY_KK_RESULT_BADGE',
            ]
        );

        while ($element = $elements->Fetch()) {
            $entityType = strtoupper((string)($element['PROPERTY_KK_ENTITY_TYPE_VALUE'] ?? ''));
            if ($entityType === 'QUESTION') {
                $items['questions'][] = $this->mapQuestion($element);
            }
            if ($entityType === 'RESULT') {
                $items['results'][] = $this->mapResult($element);
            }
        }

        return $items;
    }

    private function mapQuestion(array $element): array
    {
        return [
            'id' => (int)$element['ID'],
            'code' => (string)($element['CODE'] ?? ''),
            'name' => (string)($element['NAME'] ?? ''),
            'hint' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'sort' => (int)($element['SORT'] ?? 0),
            'question_type' => (string)($element['PROPERTY_KK_QUESTION_TYPE_VALUE'] ?? ''),
            'display_template' => (string)($element['PROPERTY_KK_DISPLAY_TEMPLATE_VALUE'] ?? ''),
            'is_required' => $this->toBool($element['PROPERTY_KK_IS_REQUIRED_VALUE'] ?? null),
            'placeholder' => (string)($element['PROPERTY_KK_PLACEHOLDER_VALUE'] ?? ''),
            'default_next_question_id' => $this->toNullableInt($element['PROPERTY_KK_DEFAULT_NEXT_QUESTION_VALUE'] ?? null),
            'answers' => $this->normalizeAnswers($element['PROPERTY_KK_ANSWERS_VALUE'] ?? []),
        ];
    }

    private function mapResult(array $element): array
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
            'min_score' => (int)($element['PROPERTY_KK_RESULT_MIN_SCORE_VALUE'] ?? 0),
            'max_score' => (int)($element['PROPERTY_KK_RESULT_MAX_SCORE_VALUE'] ?? 0),
            'priority' => (int)($element['PROPERTY_KK_RESULT_PRIORITY_VALUE'] ?? 0),
            'cta_text' => (string)($element['PROPERTY_KK_RESULT_CTA_TEXT_VALUE'] ?? ''),
            'cta_link' => (string)($element['PROPERTY_KK_RESULT_CTA_LINK_VALUE'] ?? ''),
            'show_form' => $this->toBool($element['PROPERTY_KK_RESULT_SHOW_FORM_VALUE'] ?? null),
            'catalog_section_id' => $this->toNullableInt($element['PROPERTY_KK_RESULT_CATALOG_SECTION_VALUE'] ?? null),
            'catalog_product_ids' => $this->normalizeIntList($element['PROPERTY_KK_RESULT_CATALOG_PRODUCTS_VALUE'] ?? []),
            'badge' => (string)($element['PROPERTY_KK_RESULT_BADGE_VALUE'] ?? ''),
        ];
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

    private function normalizeStringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(static fn (mixed $item): string => (string)$item, $values), static fn (string $item): bool => $item !== ''));
    }

    private function normalizeIntList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $item) {
            $item = (int)$item;
            if ($item > 0) {
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
