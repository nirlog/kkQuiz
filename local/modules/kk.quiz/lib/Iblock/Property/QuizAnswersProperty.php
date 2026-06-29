<?php

declare(strict_types=1);

namespace Kk\Quiz\Iblock\Property;

final class QuizAnswersProperty
{
    public const USER_TYPE = 'kk_quiz_answers';

    /**
     * TODO: replace IMAGE_ID numeric input with a Bitrix file selector/uploader in a follow-up PR.
     */
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

    public static function getPropertyFieldHtml(array $property, array $value, array $htmlControlName): string
    {
        $iblockId = (int)($property['IBLOCK_ID'] ?? 0);
        $sectionId = self::getCurrentSectionId($property, $iblockId);
        $answers = self::normalizeRows(self::extractRawValue($value));
        $questions = self::getElementOptions($iblockId, 'QUESTION', $sectionId);
        $results = self::getElementOptions($iblockId, 'RESULT', $sectionId);
        $inputName = (string)($htmlControlName['VALUE'] ?? '');
        $tableId = 'kk-quiz-answers-' . md5($inputName);

        if ($answers === []) {
            $answers[] = self::getDefaultRow();
        }

        $html = '<div class="kk-quiz-answers" id="' . htmlspecialcharsbx($tableId) . '">';
        $html .= '<input type="hidden" name="' . htmlspecialcharsbx($inputName . '[rows_present]') . '" value="Y">';
        $html .= '<table class="internal kk-quiz-answers__table" style="width:100%; min-width:1200px;">';
        $html .= '<thead><tr>';
        $html .= '<th>Активен</th>';
        $html .= '<th>Сорт.</th>';
        $html .= '<th>Картинка<br><small>ID файла</small></th>';
        $html .= '<th>Текст ответа</th>';
        $html .= '<th>Код</th>';
        $html .= '<th>Описание</th>';
        $html .= '<th>Следующий вопрос</th>';
        $html .= '<th>Результат</th>';
        $html .= '<th>Баллы к результату</th>';
        $html .= '<th>Баллы</th>';
        $html .= '<th>Удалить</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($answers as $index => $answer) {
            $html .= self::renderRow($inputName, (int)$index, $answer, $questions, $results);
        }

        $html .= '</tbody></table>';
        $html .= '<input type="button" class="adm-btn kk-quiz-answers__add" value="+ Добавить ответ">';
        $html .= '<script>' . self::renderScript($tableId, $inputName, $questions, $results) . '</script>';
        $html .= '</div>';

        return $html;
    }

    public static function convertToDb(array $property, array $value): array
    {
        $iblockId = (int)($property['IBLOCK_ID'] ?? 0);
        $sectionId = self::getCurrentSectionId($property, $iblockId);
        $answers = self::normalizeRows(self::extractRawValue($value), $iblockId, $sectionId, true);

        $json = $answers === [] ? '' : json_encode($answers, JSON_UNESCAPED_UNICODE);

        return [
            'VALUE' => is_string($json) ? $json : '',
        ];
    }

    public static function convertFromDb(array $property, array $value): array
    {
        return [
            'VALUE' => self::normalizeRows(self::extractRawValue($value)),
        ];
    }

    public static function checkFields(array $property, array $value): array
    {
        self::normalizeRows(self::extractRawValue($value));

        return [];
    }

    private static function extractRawValue(array $value): mixed
    {
        return $value['VALUE'] ?? $value;
    }

    private static function normalizeRows(mixed $rawValue, int $iblockId = 0, ?int $sectionId = null, bool $validateLinks = false): array
    {
        if (is_string($rawValue)) {
            $rawValue = trim($rawValue);
            if ($rawValue === '') {
                return [];
            }

            $decoded = json_decode($rawValue, true);
            if (!is_array($decoded)) {
                return [];
            }

            $rawValue = $decoded;
        }

        if (!is_array($rawValue)) {
            return [];
        }

        $rows = $rawValue['rows'] ?? $rawValue;
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $text = self::sanitizeText($row['text'] ?? $row['TEXT'] ?? '');
            if ($text === '') {
                continue;
            }

            $nextQuestionId = self::toNullableInt($row['next_question_id'] ?? $row['NEXT_QUESTION_ID'] ?? null);
            $resultId = self::toNullableInt($row['result_id'] ?? $row['RESULT_ID'] ?? null);
            $scoreResultId = self::toNullableInt($row['score_result_id'] ?? $row['SCORE_RESULT_ID'] ?? null);

            if ($validateLinks) {
                $nextQuestionId = self::filterExistingElementId($nextQuestionId, $iblockId, 'QUESTION', $sectionId);
                $resultId = self::filterExistingElementId($resultId, $iblockId, 'RESULT', $sectionId);
                $scoreResultId = self::filterExistingElementId($scoreResultId, $iblockId, 'RESULT', $sectionId);
            }

            $result[] = [
                'active' => (($row['active'] ?? $row['ACTIVE'] ?? 'N') === 'Y') ? 'Y' : 'N',
                'sort' => self::toInt($row['sort'] ?? $row['SORT'] ?? 100),
                'text' => $text,
                'code' => self::sanitizeText($row['code'] ?? $row['CODE'] ?? ''),
                'image_id' => self::toNullableInt($row['image_id'] ?? $row['IMAGE_ID'] ?? null),
                'description' => self::sanitizeText($row['description'] ?? $row['DESCRIPTION'] ?? ''),
                'next_question_id' => $nextQuestionId,
                'result_id' => $resultId,
                'score_result_id' => $scoreResultId,
                'score_value' => self::toInt($row['score_value'] ?? $row['SCORE_VALUE'] ?? 0),
            ];
        }

        usort($result, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return $result;
    }

    private static function sanitizeText(mixed $value): string
    {
        return trim(strip_tags((string)$value));
    }

    private static function toInt(mixed $value): int
    {
        return (int)$value;
    }

    private static function toNullableInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private static function getDefaultRow(): array
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

    private static function getElementOptions(int $iblockId, string $entityType, ?int $sectionId): array
    {
        if ($iblockId <= 0 || !class_exists('CIBlockElement')) {
            return [];
        }

        $filter = self::getElementFilter($iblockId, $entityType, $sectionId);
        $options = [];
        $elements = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'NAME']
        );

        while ($element = $elements->Fetch()) {
            $options[(int)$element['ID']] = '[' . (int)$element['ID'] . '] ' . (string)$element['NAME'];
        }

        return $options;
    }

    private static function getCurrentSectionId(array $property, int $iblockId): ?int
    {
        $sectionId = self::toNullableInt($property['IBLOCK_SECTION_ID'] ?? null);
        if ($sectionId !== null) {
            return $sectionId;
        }

        $requestSectionId = self::toNullableInt($_REQUEST['IBLOCK_SECTION_ID'] ?? $_REQUEST['find_section_section'] ?? null);
        if ($requestSectionId !== null) {
            return $requestSectionId;
        }

        $elementId = self::getCurrentElementId($property);
        if ($elementId === null || $iblockId <= 0 || !class_exists('CIBlockElement')) {
            return null;
        }

        $elements = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId, 'IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', 'IBLOCK_SECTION_ID']
        );
        $element = $elements->Fetch();

        return is_array($element) ? self::toNullableInt($element['IBLOCK_SECTION_ID'] ?? null) : null;
    }

    private static function getCurrentElementId(array $property): ?int
    {
        return self::toNullableInt($property['ELEMENT_ID'] ?? $_REQUEST['ID'] ?? $_REQUEST['ELEMENT_ID'] ?? null);
    }

    private static function filterExistingElementId(?int $elementId, int $iblockId, string $entityType, ?int $sectionId): ?int
    {
        if ($elementId === null || $iblockId <= 0 || !class_exists('CIBlockElement')) {
            return null;
        }

        $filter = self::getElementFilter($iblockId, $entityType, $sectionId);
        $filter['ID'] = $elementId;

        $elements = \CIBlockElement::GetList([], $filter, false, ['nTopCount' => 1], ['ID']);
        $element = $elements->Fetch();

        return is_array($element) ? $elementId : null;
    }

    private static function getElementFilter(int $iblockId, string $entityType, ?int $sectionId): array
    {
        $filter = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'PROPERTY_KK_ENTITY_TYPE' => $entityType,
        ];

        if ($sectionId !== null) {
            $filter['SECTION_ID'] = $sectionId;
            $filter['INCLUDE_SUBSECTIONS'] = 'N';
        }

        return $filter;
    }

    private static function renderRow(string $inputName, int $index, array $answer, array $questions, array $results): string
    {
        $prefix = $inputName . '[rows][' . $index . ']';
        $html = '<tr>';
        $html .= '<td style="text-align:center;"><input type="checkbox" name="' . htmlspecialcharsbx($prefix . '[active]') . '" value="Y"' . ($answer['active'] === 'Y' ? ' checked' : '') . '></td>';
        $html .= '<td><input type="number" name="' . htmlspecialcharsbx($prefix . '[sort]') . '" value="' . htmlspecialcharsbx((string)$answer['sort']) . '" style="width:70px;"></td>';
        $html .= '<td>' . self::renderImageInput($prefix, $answer) . '</td>';
        $html .= '<td><input type="text" name="' . htmlspecialcharsbx($prefix . '[text]') . '" value="' . htmlspecialcharsbx($answer['text']) . '" style="width:220px;"></td>';
        $html .= '<td><input type="text" name="' . htmlspecialcharsbx($prefix . '[code]') . '" value="' . htmlspecialcharsbx($answer['code']) . '" style="width:120px;"></td>';
        $html .= '<td><input type="text" name="' . htmlspecialcharsbx($prefix . '[description]') . '" value="' . htmlspecialcharsbx($answer['description']) . '" style="width:220px;"></td>';
        $html .= '<td>' . self::renderSelect($prefix . '[next_question_id]', $answer['next_question_id'], $questions) . '</td>';
        $html .= '<td>' . self::renderSelect($prefix . '[result_id]', $answer['result_id'], $results) . '</td>';
        $html .= '<td>' . self::renderSelect($prefix . '[score_result_id]', $answer['score_result_id'], $results) . '</td>';
        $html .= '<td><input type="number" name="' . htmlspecialcharsbx($prefix . '[score_value]') . '" value="' . htmlspecialcharsbx((string)$answer['score_value']) . '" style="width:80px;"></td>';
        $html .= '<td style="text-align:center;"><input type="checkbox" data-kk-quiz-answer-delete="Y"></td>';
        $html .= '</tr>';

        return $html;
    }

    private static function renderImageInput(string $prefix, array $answer): string
    {
        $imageId = (int)($answer['image_id'] ?? 0);
        $html = '<input type="number" name="' . htmlspecialcharsbx($prefix . '[image_id]') . '" value="' . ($imageId > 0 ? htmlspecialcharsbx((string)$imageId) : '') . '" style="width:90px;" placeholder="ID файла">';
        $html .= '<br><small>Укажите ID файла из медиабиблиотеки/файлов Битрикса</small>';

        if ($imageId > 0 && class_exists('CFile')) {
            $path = \CFile::GetPath($imageId);
            if (is_string($path) && $path !== '') {
                $html .= '<br><a href="' . htmlspecialcharsbx($path) . '" target="_blank" rel="noopener">';
                $html .= '<img src="' . htmlspecialcharsbx($path) . '" alt="" style="display:block; max-width:80px; max-height:60px; margin-top:4px;">';
                $html .= 'Файл #' . htmlspecialcharsbx((string)$imageId) . '</a>';
            }
        }

        return $html;
    }

    private static function renderSelect(string $name, ?int $selectedValue, array $options): string
    {
        $html = '<select name="' . htmlspecialcharsbx($name) . '" style="max-width:220px;">';
        $html .= '<option value="">—</option>';

        foreach ($options as $id => $title) {
            $selected = ((int)$id === (int)$selectedValue) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialcharsbx((string)$id) . '"' . $selected . '>' . htmlspecialcharsbx($title) . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    private static function renderScript(string $tableId, string $inputName, array $questions, array $results): string
    {
        $template = self::renderRow($inputName, 0, self::getDefaultRow(), $questions, $results);

        return '(() => {'
            . 'const root = document.getElementById(' . self::json($tableId) . ');'
            . 'if (!root) return;'
            . 'const tbody = root.querySelector("tbody");'
            . 'const addButton = root.querySelector(".kk-quiz-answers__add");'
            . 'const template = ' . self::json($template) . ';'
            . 'const getNextIndex = () => tbody.querySelectorAll("tr").length + Date.now();'
            . 'addButton.addEventListener("click", () => {'
            . 'tbody.insertAdjacentHTML("beforeend", template.split("[rows][0]").join(`[rows][${getNextIndex()}]`));'
            . '});'
            . 'root.addEventListener("change", (event) => {'
            . 'if (event.target.matches("[data-kk-quiz-answer-delete]")) {'
            . 'event.target.closest("tr").remove();'
            . '}'
            . '});'
            . '})();';
    }

    private static function json(string $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
