<?php

declare(strict_types=1);

namespace Kk\Quiz\Iblock\Property;

final class QuizAnswersProperty
{
    private const USER_TYPE = 'kk_quiz_answers';
    private const ENTITY_TYPE_QUESTION = 'QUESTION';
    private const ENTITY_TYPE_RESULT = 'RESULT';

    public static function getUserTypeDescription(): array
    {
        return [
            'PROPERTY_TYPE' => 'S',
            'USER_TYPE' => self::USER_TYPE,
            'DESCRIPTION' => 'KK Quiz: ответы квиза',
            'GetPropertyFieldHtml' => [self::class, 'getPropertyFieldHtml'],
            'ConvertToDB' => [self::class, 'convertToDb'],
            'ConvertFromDB' => [self::class, 'convertFromDb'],
            'CheckFields' => [self::class, 'checkFields'],
        ];
    }

    public static function getPropertyFieldHtml(array $property, array $value, array $control): string
    {
        $iblockId = (int)($property['IBLOCK_ID'] ?? 0);
        $sectionId = self::getCurrentSectionId($property);
        $questionOptions = self::getElementOptions($iblockId, self::ENTITY_TYPE_QUESTION, $sectionId);
        $resultOptions = self::getElementOptions($iblockId, self::ENTITY_TYPE_RESULT, $sectionId);
        $answers = self::decodeAnswers($value['VALUE'] ?? []);
        $fieldName = (string)($control['VALUE'] ?? 'VALUE');

        if ($answers === []) {
            $answers[] = self::getEmptyAnswer();
        }

        $html = '<div class="kk-quiz-answers">';
        foreach ($answers as $index => $answer) {
            $html .= self::renderAnswerCard($fieldName, $index, $answer, $questionOptions, $resultOptions);
        }
        $html .= '</div>';

        return $html;
    }

    public static function convertToDb(array $property, array $value): array
    {
        $iblockId = (int)($property['IBLOCK_ID'] ?? 0);
        $sectionId = self::getCurrentSectionId($property);
        $rows = self::decodeAnswers($value['VALUE'] ?? []);
        $answers = [];

        foreach ($rows as $row) {
            $text = trim(self::sanitizeString($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $answers[] = [
                'active' => (($row['active'] ?? 'N') === 'Y') ? 'Y' : 'N',
                'sort' => (int)($row['sort'] ?? 100),
                'text' => $text,
                'code' => self::sanitizeString($row['code'] ?? ''),
                'image_id' => self::toNullableInt($row['image_id'] ?? null),
                'description' => self::sanitizeString($row['description'] ?? ''),
                'next_question_id' => self::filterExistingElementId($iblockId, self::ENTITY_TYPE_QUESTION, self::toNullableInt($row['next_question_id'] ?? null), $sectionId),
                'result_id' => self::filterExistingElementId($iblockId, self::ENTITY_TYPE_RESULT, self::toNullableInt($row['result_id'] ?? null), $sectionId),
                'score_result_id' => self::filterExistingElementId($iblockId, self::ENTITY_TYPE_RESULT, self::toNullableInt($row['score_result_id'] ?? null), $sectionId),
                'score_value' => (int)($row['score_value'] ?? 0),
            ];
        }

        usort($answers, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);
        $value['VALUE'] = $answers === [] ? '' : json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $value;
    }

    public static function convertFromDb(array $property, array $value): array
    {
        if (isset($value['VALUE']) && is_string($value['VALUE']) && $value['VALUE'] !== '') {
            $decoded = json_decode($value['VALUE'], true);
            $value['VALUE'] = is_array($decoded) ? $decoded : [];
        }

        return $value;
    }

    public static function checkFields(array $property, array $value): array
    {
        return [];
    }

    private static function getElementOptions(int $iblockId, string $entityType, ?int $sectionId): array
    {
        if ($iblockId <= 0 || !class_exists('CIBlockElement')) {
            return [];
        }

        $filter = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
        ];

        if ($sectionId !== null) {
            $filter['SECTION_ID'] = $sectionId;
            $filter['INCLUDE_SUBSECTIONS'] = 'N';
        }

        $options = [];
        $elements = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'NAME', 'SORT']
        );

        while ($elementObject = $elements->GetNextElement()) {
            $fields = $elementObject->GetFields();
            $properties = $elementObject->GetProperties();
            $currentEntityType = self::getPropertyEnumXmlId($properties, 'KK_ENTITY_TYPE');

            if ($currentEntityType === '') {
                $currentEntityType = (string)self::getPropertyValue($properties, 'KK_ENTITY_TYPE');
            }

            if (strtoupper($currentEntityType) !== strtoupper($entityType)) {
                continue;
            }

            $id = (int)($fields['ID'] ?? 0);
            if ($id > 0) {
                $options[$id] = '[' . $id . '] ' . (string)($fields['NAME'] ?? '');
            }
        }

        return $options;
    }

    private static function filterExistingElementId(int $iblockId, string $entityType, ?int $elementId, ?int $sectionId): ?int
    {
        if ($iblockId <= 0 || $elementId === null || !class_exists('CIBlockElement')) {
            return null;
        }

        $filter = [
            'ID' => $elementId,
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
        ];

        if ($sectionId !== null) {
            $filter['SECTION_ID'] = $sectionId;
            $filter['INCLUDE_SUBSECTIONS'] = 'N';
        }

        $elements = \CIBlockElement::GetList(
            [],
            $filter,
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME']
        );
        $elementObject = $elements->GetNextElement();
        if (!$elementObject) {
            return null;
        }

        $properties = $elementObject->GetProperties();
        $currentEntityType = self::getPropertyEnumXmlId($properties, 'KK_ENTITY_TYPE');
        if ($currentEntityType === '') {
            $currentEntityType = (string)self::getPropertyValue($properties, 'KK_ENTITY_TYPE');
        }

        return strtoupper($currentEntityType) === strtoupper($entityType) ? $elementId : null;
    }

    private static function getPropertyEnumXmlId(array $properties, string $code): string
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return '';
        }

        $property = $properties[$code];
        $xmlId = $property['VALUE_XML_ID'] ?? '';
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

        if ($enumId !== null && class_exists('CIBlockPropertyEnum')) {
            $enum = \CIBlockPropertyEnum::GetByID((int)$enumId);
            if (is_array($enum)) {
                $xmlId = (string)($enum['XML_ID'] ?? '');
                if ($xmlId !== '') {
                    return $xmlId;
                }
            }
        }

        $value = $property['VALUE'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private static function getPropertyValue(array $properties, string $code): mixed
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return null;
        }

        return $properties[$code]['VALUE'] ?? null;
    }

    private static function getCurrentSectionId(array $property): ?int
    {
        $sectionId = self::toNullableInt($property['IBLOCK_SECTION_ID'] ?? null);
        if ($sectionId !== null) {
            return $sectionId;
        }

        foreach (['IBLOCK_SECTION_ID', 'find_section_section', 'IBLOCK_SECTION'] as $requestKey) {
            $sectionId = self::extractPositiveInt($_REQUEST[$requestKey] ?? null);
            if ($sectionId !== null) {
                return $sectionId;
            }
        }

        $elementId = self::extractPositiveInt($property['ELEMENT_ID'] ?? ($_REQUEST['ID'] ?? null));
        if ($elementId !== null && class_exists('CIBlockElement')) {
            $element = \CIBlockElement::GetList([], ['ID' => $elementId], false, ['nTopCount' => 1], ['ID', 'IBLOCK_SECTION_ID'])->Fetch();
            if (is_array($element)) {
                return self::toNullableInt($element['IBLOCK_SECTION_ID'] ?? null);
            }
        }

        return null;
    }

    private static function extractPositiveInt(mixed $value): ?int
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $id = self::extractPositiveInt($item);
                if ($id !== null) {
                    return $id;
                }
            }

            return null;
        }

        return self::toNullableInt($value);
    }

    private static function decodeAnswers(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row)));
        }

        return [$value];
    }

    private static function renderAnswerCard(string $fieldName, int $index, array $answer, array $questionOptions, array $resultOptions): string
    {
        $prefix = $fieldName . '[' . $index . ']';
        $html = '<div class="kk-quiz-answers__item">';
        $html .= '<label>Активен <input type="checkbox" name="' . self::e($prefix . '[active]') . '" value="Y"' . (((string)($answer['active'] ?? 'Y') === 'Y') ? ' checked' : '') . '></label> ';
        $html .= '<label>Сорт. <input type="number" name="' . self::e($prefix . '[sort]') . '" value="' . self::e((string)($answer['sort'] ?? 100)) . '"></label> ';
        $html .= '<label>Текст <input type="text" name="' . self::e($prefix . '[text]') . '" value="' . self::e((string)($answer['text'] ?? '')) . '"></label> ';
        $html .= '<label>Код <input type="text" name="' . self::e($prefix . '[code]') . '" value="' . self::e((string)($answer['code'] ?? '')) . '"></label> ';
        $html .= '<label>ID файла <input type="number" name="' . self::e($prefix . '[image_id]') . '" value="' . self::e((string)($answer['image_id'] ?? '')) . '"></label> ';
        $html .= '<label>Описание <input type="text" name="' . self::e($prefix . '[description]') . '" value="' . self::e((string)($answer['description'] ?? '')) . '"></label> ';
        $html .= '<label>Следующий вопрос ' . self::renderSelect($prefix . '[next_question_id]', self::toNullableInt($answer['next_question_id'] ?? null), $questionOptions) . '</label> ';
        $html .= '<label>Финальный результат ' . self::renderSelect($prefix . '[result_id]', self::toNullableInt($answer['result_id'] ?? null), $resultOptions) . '</label> ';
        $html .= '<label>Результат для начисления баллов ' . self::renderSelect($prefix . '[score_result_id]', self::toNullableInt($answer['score_result_id'] ?? null), $resultOptions) . '</label> ';
        $html .= '<label>Баллы <input type="number" name="' . self::e($prefix . '[score_value]') . '" value="' . self::e((string)($answer['score_value'] ?? 0)) . '"></label>';
        $html .= '</div>';

        return $html;
    }

    private static function renderSelect(string $name, ?int $selectedId, array $options): string
    {
        $html = '<select name="' . self::e($name) . '"><option value="">(не выбрано)</option>';
        foreach ($options as $id => $label) {
            $id = (int)$id;
            $html .= '<option value="' . $id . '"' . (($selectedId === $id) ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    private static function getEmptyAnswer(): array
    {
        return [
            'active' => 'Y',
            'sort' => 100,
            'text' => '',
            'code' => '',
            'image_id' => null,
            'description' => '',
            'next_question_id' => null,
            'result_id' => null,
            'score_result_id' => null,
            'score_value' => 0,
        ];
    }

    private static function sanitizeString(mixed $value): string
    {
        return trim(strip_tags(is_scalar($value) ? (string)$value : ''));
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if (is_array($value)) {
            return self::extractPositiveInt($value);
        }

        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private static function e(string $value): string
    {
        return function_exists('htmlspecialcharsbx') ? htmlspecialcharsbx($value) : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
