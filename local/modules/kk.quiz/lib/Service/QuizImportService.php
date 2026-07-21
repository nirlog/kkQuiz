<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

final class QuizImportService
{
    private const SCHEMA = 'kk.quiz.export.v1';
    private const MODULE = 'kk.quiz';
    private const VERSION = 1;

    /** @var string[] */
    private array $warnings = [];

    public function import(array $data): array
    {
        $this->warnings = [];
        $this->validateImportData($data);

        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('QUIZ_IBLOCK_NOT_FOUND');
        }

        $iblockId = $this->getQuizIblockId();
        if ($iblockId === null) {
            throw new \RuntimeException('QUIZ_IBLOCK_NOT_FOUND');
        }

        $quiz = is_array($data['quiz']) ? $data['quiz'] : [];
        $settings = is_array($quiz['settings'] ?? null) ? $quiz['settings'] : [];
        $sectionCode = $this->makeUniqueSectionCode($iblockId, (string)($quiz['code'] ?? ''));
        $quizName = trim((string)($quiz['name'] ?? '')) ?: 'Imported quiz';
        $sectionId = $this->createSection($iblockId, $sectionCode, $quizName, $quiz, $settings);

        $resultCodeToNewId = [];
        $resultsCount = 0;
        foreach ($data['results'] as $result) {
            if (!is_array($result)) {
                continue;
            }

            $sourceCode = trim((string)($result['code'] ?? ''));
            $newCode = $this->makeUniqueElementCode($iblockId, $sourceCode);
            $newId = $this->createResult($iblockId, $sectionId, $newCode, $result);
            $resultsCount++;
            if ($sourceCode !== '') {
                $resultCodeToNewId[$sourceCode] = $newId;
            }
        }

        $questionCodeToNewId = [];
        $questionsCount = 0;
        foreach ($data['questions'] as $question) {
            if (!is_array($question)) {
                continue;
            }

            $sourceCode = trim((string)($question['code'] ?? ''));
            $newCode = $this->makeUniqueElementCode($iblockId, $sourceCode);
            $newId = $this->createQuestion($iblockId, $sectionId, $newCode, $question);
            $questionsCount++;
            if ($sourceCode !== '') {
                $questionCodeToNewId[$sourceCode] = $newId;
            }
        }

        foreach ($data['questions'] as $question) {
            if (is_array($question)) {
                $this->updateQuestionLinks($iblockId, $question, $questionCodeToNewId, $resultCodeToNewId);
            }
        }

        $this->updateStartQuestion($sectionId, (string)($quiz['start_question_code'] ?? ''), $questionCodeToNewId);

        if ($this->hasCatalogReferences($data['results'])) {
            $this->warnings[] = 'Рекомендации импортированы по исходным ID. На другом сайте их нужно проверить вручную.';
        }

        return [
            'section_id' => $sectionId,
            'quiz_code' => $sectionCode,
            'quiz_name' => $quizName,
            'questions_count' => $questionsCount,
            'results_count' => $resultsCount,
            'warnings' => array_values(array_unique($this->warnings)),
            'admin_url' => $this->buildElementListUrl($iblockId, $sectionId),
        ];
    }

    private function validateImportData(array $data): void
    {
        if (
            ($data['schema'] ?? null) !== self::SCHEMA
            || ($data['module'] ?? null) !== self::MODULE
            || (int)($data['version'] ?? 0) !== self::VERSION
            || !isset($data['quiz'])
            || !is_array($data['quiz'])
            || !isset($data['questions'])
            || !is_array($data['questions'])
            || !isset($data['results'])
            || !is_array($data['results'])
        ) {
            throw new \RuntimeException('INVALID_IMPORT_FORMAT');
        }
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

    private function createSection(int $iblockId, string $code, string $name, array $quiz, array $settings): int
    {
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'NAME' => $name,
            'DESCRIPTION' => (string)($quiz['description'] ?? ''),
            'SORT' => (int)($quiz['sort'] ?? 500),
            'UF_KK_TITLE' => (string)($settings['title'] ?? ''),
            'UF_KK_SUBTITLE' => (string)($settings['subtitle'] ?? ''),
            'UF_KK_BUTTON_TEXT' => (string)($settings['button_text'] ?? ''),
            'UF_KK_FORM_BUTTON_TEXT' => (string)($settings['form_button_text'] ?? ''),
            'UF_KK_FORM_TITLE' => (string)($settings['form_title'] ?? ''),
            'UF_KK_FORM_SUBTITLE' => (string)($settings['form_subtitle'] ?? ''),
            'UF_KK_START_TEXT' => (string)($settings['start_text'] ?? ''),
            'UF_KK_SUCCESS_TEXT' => (string)($settings['success_text'] ?? ''),
            'UF_KK_EMAIL_TO' => (string)($settings['email_to'] ?? ''),
            'UF_KK_FORM_FIELDS' => $this->mapUserFieldEnumValues('UF_KK_FORM_FIELDS', $settings['form_fields'] ?? []),
            'UF_KK_REQUIRED_FIELDS' => $this->mapUserFieldEnumValues('UF_KK_REQUIRED_FIELDS', $settings['required_fields'] ?? []),
            'UF_KK_METRIKA_COUNTER_ID' => (string)($settings['metrika_counter_id'] ?? ''),
            'UF_KK_METRIKA_GOAL' => (string)($settings['metrika_goal'] ?? ''),
            'UF_KK_USE_METRIKA' => $this->toBool($settings['use_metrika'] ?? null) ? 1 : 0,
            'UF_KK_USE_CATALOG' => $this->toBool($settings['use_catalog'] ?? null) ? 1 : 0,
            'UF_KK_CATALOG_IBLOCK_ID' => $this->toNullableInt($settings['catalog_iblock_id'] ?? null),
            'UF_KK_CATALOG_IBLOCK_IDS' => $this->mapUserFieldEnumValues('UF_KK_CATALOG_IBLOCK_IDS', $settings['catalog_iblock_ids'] ?? []),
            'UF_KK_THEME' => $this->getSectionUserFieldEnumId('UF_KK_THEME', (string)($settings['theme'] ?? 'default')),
            'UF_KK_ALLOW_POPUP_URL' => $this->toBool($settings['allow_popup_url'] ?? null) ? 1 : 0,
            'UF_KK_PRIVACY_TEXT' => (string)($settings['privacy_text'] ?? ''),
            'UF_KK_PRIVACY_URL' => (string)($settings['privacy_url'] ?? ''),
            'UF_KK_REQUIRE_AGREEMENT' => $this->toBool($settings['require_agreement'] ?? null) ? 1 : 0,
        ];

        $section = new \CIBlockSection();
        $sectionId = (int)$section->Add($fields);
        if ($sectionId <= 0) {
            throw new \RuntimeException((string)($section->LAST_ERROR ?: 'SECTION_CREATE_FAILED'));
        }

        return $sectionId;
    }

    private function createResult(int $iblockId, int $sectionId, string $code, array $result): int
    {
        $video = is_array($result['video'] ?? null) ? $result['video'] : [];
        $propertyValues = [
            'KK_ENTITY_TYPE' => $this->getPropertyEnumId($iblockId, 'KK_ENTITY_TYPE', 'RESULT'),
            'KK_PUBLIC_TITLE' => (string)($result['public_title'] ?? ''),
            'KK_RESULT_MIN_SCORE' => $this->toNullableInt($result['min_score'] ?? null),
            'KK_RESULT_MAX_SCORE' => $this->toNullableInt($result['max_score'] ?? null),
            'KK_RESULT_PRIORITY' => (int)($result['priority'] ?? 100),
            'KK_RESULT_SUMMARY' => (string)($result['summary'] ?? ''),
            'KK_RESULT_WHY_TEXT' => (string)($result['why_text'] ?? ''),
            'KK_RESULT_SPECS_TEXT' => (string)($result['specs_text'] ?? ''),
            'KK_RESULT_NOTE_TEXT' => (string)($result['note_text'] ?? ''),
            'KK_RESULT_CTA_TEXT' => (string)($result['cta_text'] ?? ''),
            'KK_RESULT_CTA_LINK' => (string)($result['cta_link'] ?? ''),
            'KK_RESULT_VIDEO_URL' => (string)($result['video_url'] ?? $video['url'] ?? ''),
            'KK_RESULT_VIDEO_TITLE' => (string)($result['video_title'] ?? $video['title'] ?? ''),
            'KK_RESULT_VIDEO_POSITION' => $this->getPropertyEnumId(
                $iblockId,
                'KK_RESULT_VIDEO_POSITION',
                (string)($result['video_position'] ?? $video['position'] ?? '')
            ),
            'KK_RESULT_FORM_TITLE' => (string)($result['form_title'] ?? ''),
            'KK_RESULT_FORM_INTRO' => (string)($result['form_intro'] ?? ''),
            'KK_RESULT_FORM_BUTTON_TEXT' => (string)($result['form_button_text'] ?? ''),
            'KK_RESULT_SHOW_FORM' => $this->getPropertyEnumId($iblockId, 'KK_RESULT_SHOW_FORM', $this->toBool($result['show_form'] ?? true) ? 'Y' : 'N'),
            'KK_RESULT_CATALOG_SECTION' => $this->toNullableInt($result['catalog_section_id'] ?? null),
            'KK_RESULT_CATALOG_PRODUCTS' => $this->normalizeIntList($result['catalog_product_ids'] ?? []),
            'KK_RESULT_BADGE' => (string)($result['badge'] ?? ''),
        ];

        return $this->addElement($iblockId, $sectionId, $code, $result, $propertyValues, 'RESULT_CREATE_FAILED');
    }

    private function createQuestion(int $iblockId, int $sectionId, string $code, array $question): int
    {
        $propertyValues = [
            'KK_ENTITY_TYPE' => $this->getPropertyEnumId($iblockId, 'KK_ENTITY_TYPE', 'QUESTION'),
            'KK_PUBLIC_TITLE' => (string)($question['public_title'] ?? ''),
            'KK_QUESTION_TYPE' => $this->getPropertyEnumId($iblockId, 'KK_QUESTION_TYPE', (string)($question['question_type'] ?? 'radio')),
            'KK_DISPLAY_TEMPLATE' => $this->getPropertyEnumId($iblockId, 'KK_DISPLAY_TEMPLATE', (string)($question['display_template'] ?? 'list')),
            'KK_IS_REQUIRED' => $this->getPropertyEnumId($iblockId, 'KK_IS_REQUIRED', $this->toBool($question['is_required'] ?? null) ? 'Y' : 'N'),
            'KK_PLACEHOLDER' => (string)($question['placeholder'] ?? ''),
            'KK_ALLOW_CUSTOM_ANSWER' => $this->getPropertyEnumId($iblockId, 'KK_ALLOW_CUSTOM_ANSWER', $this->toBool($question['allow_custom_answer'] ?? null) ? 'Y' : 'N'),
        ];

        return $this->addElement($iblockId, $sectionId, $code, $question, $propertyValues, 'QUESTION_CREATE_FAILED');
    }

    private function addElement(int $iblockId, int $sectionId, string $code, array $data, array $propertyValues, string $fallbackError): int
    {
        $name = trim((string)($data['admin_name'] ?? $data['name'] ?? $data['public_title'] ?? '')) ?: $code;
        $element = new \CIBlockElement();
        $elementId = (int)$element->Add([
            'IBLOCK_ID' => $iblockId,
            'IBLOCK_SECTION_ID' => $sectionId,
            'ACTIVE' => $this->toBool($data['active'] ?? true) ? 'Y' : 'N',
            'SORT' => (int)($data['sort'] ?? 500),
            'CODE' => $code,
            'NAME' => $name,
            'PREVIEW_TEXT' => (string)($data['preview_text'] ?? ''),
            'DETAIL_TEXT' => (string)($data['detail_text'] ?? ''),
            'PROPERTY_VALUES' => array_filter($propertyValues, static fn (mixed $value): bool => $value !== null),
        ]);

        if ($elementId <= 0) {
            throw new \RuntimeException((string)($element->LAST_ERROR ?: $fallbackError));
        }

        return $elementId;
    }

    private function updateQuestionLinks(int $iblockId, array $question, array $questionCodeToNewId, array $resultCodeToNewId): void
    {
        $sourceCode = trim((string)($question['code'] ?? ''));
        $questionId = $sourceCode !== '' ? (int)($questionCodeToNewId[$sourceCode] ?? 0) : 0;
        if ($questionId <= 0) {
            return;
        }

        $defaultNextQuestionId = $this->resolveQuestionId((string)($question['default_next_question_code'] ?? ''), $questionCodeToNewId, 'следующего вопроса по умолчанию');
        $defaultResultId = $this->resolveResultId((string)($question['default_result_code'] ?? ''), $resultCodeToNewId, 'результата по умолчанию');
        $answers = [];

        foreach ((array)($question['answers'] ?? []) as $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $answerText = (string)($answer['text'] ?? '');
            $nextQuestionId = $this->resolveQuestionId((string)($answer['next_question_code'] ?? ''), $questionCodeToNewId, 'ответа "' . $answerText . '"');
            $resultId = $this->resolveResultId((string)($answer['result_code'] ?? ''), $resultCodeToNewId, 'ответа "' . $answerText . '"');
            $scoreResultId = $this->resolveResultId((string)($answer['score_result_code'] ?? ''), $resultCodeToNewId, 'score-ответа "' . $answerText . '"');

            if ($this->toNullableInt($answer['image_id'] ?? null) !== null || trim((string)($answer['image_src'] ?? '')) !== '') {
                $this->warnings[] = 'Изображение ответа "' . $answerText . '" не импортировано как файл.';
            }

            $answers[] = [
                'active' => $this->toBool($answer['active'] ?? true) ? 'Y' : 'N',
                'sort' => (int)($answer['sort'] ?? 100),
                'text' => $answerText,
                'code' => (string)($answer['code'] ?? ''),
                'description' => (string)($answer['description'] ?? ''),
                'image_id' => null,
                'image_src' => '',
                'next_question_id' => $nextQuestionId,
                'result_id' => $resultId,
                'score_result_id' => $scoreResultId,
                'score_value' => (int)($answer['score_value'] ?? 0),
            ];
        }

        \CIBlockElement::SetPropertyValuesEx($questionId, $iblockId, [
            'KK_DEFAULT_NEXT_QUESTION' => $defaultNextQuestionId,
            'KK_DEFAULT_RESULT' => $defaultResultId,
            'KK_ANSWERS' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function updateStartQuestion(int $sectionId, string $startQuestionCode, array $questionCodeToNewId): void
    {
        $startQuestionCode = trim($startQuestionCode);
        if ($startQuestionCode === '') {
            return;
        }

        $questionId = (int)($questionCodeToNewId[$startQuestionCode] ?? 0);
        if ($questionId <= 0) {
            $this->warnings[] = 'Не найден стартовый вопрос с code="' . $startQuestionCode . '".';
            return;
        }

        $section = new \CIBlockSection();
        $section->Update($sectionId, ['UF_KK_START_QUESTION' => $questionId]);
    }

    private function resolveQuestionId(string $code, array $questionCodeToNewId, string $context): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $id = (int)($questionCodeToNewId[$code] ?? 0);
        if ($id <= 0) {
            $this->warnings[] = 'Не найден вопрос с code="' . $code . '" для ' . $context . '.';
            return null;
        }

        return $id;
    }

    private function resolveResultId(string $code, array $resultCodeToNewId, string $context): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $id = (int)($resultCodeToNewId[$code] ?? 0);
        if ($id <= 0) {
            $this->warnings[] = 'Не найден результат с code="' . $code . '" для ' . $context . '.';
            return null;
        }

        return $id;
    }

    private function makeUniqueSectionCode(int $iblockId, string $baseCode): string
    {
        $baseCode = $this->sanitizeCode($baseCode) ?: 'imported-quiz';
        if (!$this->sectionCodeExists($iblockId, $baseCode)) {
            return $baseCode;
        }

        $candidate = $baseCode . '-copy';
        if (!$this->sectionCodeExists($iblockId, $candidate)) {
            return $candidate;
        }

        for ($index = 2; $index < 1000; $index++) {
            $candidate = $baseCode . '-copy-' . $index;
            if (!$this->sectionCodeExists($iblockId, $candidate)) {
                return $candidate;
            }
        }

        return $baseCode . '-copy-' . time();
    }

    private function makeUniqueElementCode(int $iblockId, string $baseCode): string
    {
        $baseCode = $this->sanitizeCode($baseCode) ?: 'imported-element';
        if (!$this->elementCodeExists($iblockId, $baseCode)) {
            return $baseCode;
        }

        $candidate = $baseCode . '-copy';
        if (!$this->elementCodeExists($iblockId, $candidate)) {
            return $candidate;
        }

        for ($index = 2; $index < 1000; $index++) {
            $candidate = $baseCode . '-copy-' . $index;
            if (!$this->elementCodeExists($iblockId, $candidate)) {
                return $candidate;
            }
        }

        return $baseCode . '-copy-' . time();
    }

    private function sanitizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_-]+/', '-', $code) ?: '';
        $code = trim($code, '-_');

        return $code;
    }

    private function sectionCodeExists(int $iblockId, string $code): bool
    {
        $section = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code], false, ['ID'])->Fetch();

        return is_array($section);
    }

    private function elementCodeExists(int $iblockId, string $code): bool
    {
        $element = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code], false, ['nTopCount' => 1], ['ID'])->Fetch();

        return is_array($element);
    }

    private function getSectionUserFieldEnumId(string $fieldName, string $xmlIdOrValue): ?int
    {
        $xmlIdOrValue = trim($xmlIdOrValue);
        if ($xmlIdOrValue === '' || !class_exists('CUserTypeEntity') || !class_exists('CUserFieldEnum')) {
            return null;
        }

        $field = \CUserTypeEntity::GetList([], ['FIELD_NAME' => $fieldName])->Fetch();
        $fieldId = (int)($field['ID'] ?? 0);
        if (!is_array($field) || $fieldId <= 0) {
            return null;
        }

        foreach (['XML_ID', 'VALUE'] as $lookupField) {
            $enum = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $fieldId, $lookupField => $xmlIdOrValue])->Fetch();
            if (is_array($enum) && (int)($enum['ID'] ?? 0) > 0) {
                return (int)$enum['ID'];
            }
        }

        return null;
    }

    private function mapUserFieldEnumValues(string $fieldName, mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $result = [];
        foreach ($values as $value) {
            $enumId = $this->getSectionUserFieldEnumId($fieldName, (string)$value);
            if ($enumId !== null && !in_array($enumId, $result, true)) {
                $result[] = $enumId;
            }
        }

        return $result;
    }

    private function getPropertyEnumId(int $iblockId, string $propertyCode, string $xmlIdOrValue): ?int
    {
        $xmlIdOrValue = trim($xmlIdOrValue);
        if ($xmlIdOrValue === '') {
            return null;
        }

        $property = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode])->Fetch();
        $propertyId = (int)($property['ID'] ?? 0);
        if (!is_array($property) || $propertyId <= 0) {
            return null;
        }

        foreach (['XML_ID', 'VALUE'] as $lookupField) {
            $enum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propertyId, $lookupField => $xmlIdOrValue])->Fetch();
            if (is_array($enum) && (int)($enum['ID'] ?? 0) > 0) {
                return (int)$enum['ID'];
            }
        }

        return null;
    }

    private function buildElementListUrl(int $iblockId, int $sectionId): string
    {
        return '/bitrix/admin/iblock_list_admin.php?' . http_build_query([
            'IBLOCK_ID' => $iblockId,
            'type' => Installer::IBLOCK_TYPE_ID,
            'lang' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru',
            'find_section_section' => $sectionId,
            'SECTION_ID' => $sectionId,
        ]);
    }

    private function hasCatalogReferences(array $results): bool
    {
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            if ($this->toNullableInt($result['catalog_section_id'] ?? null) !== null || $this->normalizeIntList($result['catalog_product_ids'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    private function normalizeIntList(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $result = [];
        foreach ($values as $value) {
            $value = (int)$value;
            if ($value > 0 && !in_array($value, $result, true)) {
                $result[] = $value;
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
}
