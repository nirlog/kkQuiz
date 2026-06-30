<?php

declare(strict_types=1);

namespace Kk\Quiz\Iblock\Property;

final class QuizAnswersProperty
{
    public const USER_TYPE = 'kk_quiz_answers';
    private const FILE_FIELD_NAME = 'kk_quiz_answer_image';
    private const MAX_IMAGE_SIZE = 5242880;
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

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
        $propertyId = (int)($property['ID'] ?? 0);
        $answers = self::normalizeRows(self::extractRawValue($value));
        $questions = self::getElementOptions($iblockId, 'QUESTION', $sectionId);
        $results = self::getElementOptions($iblockId, 'RESULT', $sectionId);
        $inputName = (string)($htmlControlName['VALUE'] ?? '');
        $rootId = 'kk-quiz-answers-' . md5($inputName);

        if ($answers === []) {
            $answers[] = self::getDefaultRow();
        }

        $html = '<div class="kk-quiz-answers" id="' . htmlspecialcharsbx($rootId) . '">';
        $html .= self::renderStyles($rootId);
        $html .= '<input type="hidden" name="' . htmlspecialcharsbx($inputName . '[rows_present]') . '" value="Y">';
        $html .= '<div class="kk-quiz-answers__items">';

        foreach ($answers as $index => $answer) {
            $html .= self::renderRow($inputName, $propertyId, (int)$index, (string)$index, $answer, $questions, $results);
        }

        $html .= '</div>';
        $html .= '<div class="kk-quiz-answers__actions">';
        $html .= '<input type="button" class="adm-btn kk-quiz-answers__add" value="+ Добавить ответ">';
        $html .= '</div>';
        $html .= '<script>' . self::renderScript($rootId, $inputName, $propertyId, $questions, $results) . '</script>';
        $html .= '</div>';

        return $html;
    }

    public static function convertToDb(array $property, array $value): array
    {
        $iblockId = (int)($property['IBLOCK_ID'] ?? 0);
        $sectionId = self::getCurrentSectionId($property, $iblockId);
        $propertyId = (int)($property['ID'] ?? 0);
        $answers = self::normalizeRows(self::extractRawValue($value), $iblockId, $sectionId, true, $propertyId);

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

        return self::getFileValidationErrors((int)($property['ID'] ?? 0), self::extractRawValue($value));
    }

    private static function extractRawValue(array $value): mixed
    {
        return $value['VALUE'] ?? $value;
    }

    private static function normalizeRows(mixed $rawValue, int $iblockId = 0, ?int $sectionId = null, bool $validateLinks = false, int $propertyId = 0): array
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
        foreach ($rows as $rowKey => $row) {
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

            $imageId = self::toNullableInt($row['image_id_manual'] ?? $row['image_id'] ?? $row['IMAGE_ID'] ?? null);
            $submittedRowKey = self::sanitizeRowKey((string)($row['row_key'] ?? $rowKey));

            if (($row['delete_image'] ?? 'N') === 'Y') {
                $imageId = null;
            } elseif ($validateLinks) {
                $uploadedImageId = self::saveUploadedImage($propertyId, $submittedRowKey);
                if ($uploadedImageId !== null) {
                    $imageId = $uploadedImageId;
                }
            }

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
                'image_id' => $imageId,
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
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'NAME', 'IBLOCK_ID']
        );

        while ($elementObject = $elements->GetNextElement()) {
            $fields = $elementObject->GetFields();
            $properties = $elementObject->GetProperties();
            $elementEntityType = self::getPropertyEnumXmlId(is_array($properties) ? $properties : [], 'KK_ENTITY_TYPE');

            if ($elementEntityType !== $entityType) {
                continue;
            }

            $elementId = (int)($fields['ID'] ?? 0);
            if ($elementId <= 0) {
                continue;
            }

            $options[$elementId] = '[' . $elementId . '] ' . (string)($fields['NAME'] ?? '');
        }

        return $options;
    }

    private static function getCurrentSectionId(array $property, int $iblockId): ?int
    {
        $sectionId = self::extractPositiveInt($property['IBLOCK_SECTION_ID'] ?? null);
        if ($sectionId !== null) {
            return $sectionId;
        }

        foreach (['IBLOCK_SECTION_ID', 'find_section_section', 'IBLOCK_SECTION'] as $requestKey) {
            $requestSectionId = self::extractPositiveInt($_REQUEST[$requestKey] ?? null);
            if ($requestSectionId !== null) {
                return $requestSectionId;
            }
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

        return is_array($element) ? self::extractPositiveInt($element['IBLOCK_SECTION_ID'] ?? null) : null;
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

        $filter = [
            'ID' => $elementId,
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
        ];

        if ($sectionId !== null) {
            $filter['SECTION_ID'] = $sectionId;
            $filter['INCLUDE_SUBSECTIONS'] = 'N';
        }

        $elements = \CIBlockElement::GetList([], $filter, false, ['nTopCount' => 1], ['ID', 'IBLOCK_ID']);
        $elementObject = $elements->GetNextElement();
        if (!$elementObject) {
            return null;
        }

        $properties = $elementObject->GetProperties();
        $elementEntityType = self::getPropertyEnumXmlId(is_array($properties) ? $properties : [], 'KK_ENTITY_TYPE');

        return $elementEntityType === $entityType ? $elementId : null;
    }

    private static function getPropertyEnumXmlId(array $properties, string $code): string
    {
        $property = $properties[$code] ?? null;
        if (!is_array($property)) {
            return '';
        }

        $xmlId = $property['VALUE_XML_ID'] ?? null;
        if (is_array($xmlId)) {
            $xmlId = reset($xmlId);
        }

        if (is_scalar($xmlId) && (string)$xmlId !== '') {
            return (string)$xmlId;
        }

        $enumId = $property['VALUE_ENUM_ID'] ?? null;
        if (is_array($enumId)) {
            $enumId = reset($enumId);
        }

        $enumId = self::extractPositiveInt($enumId);
        if ($enumId !== null && class_exists('CIBlockPropertyEnum')) {
            $enum = \CIBlockPropertyEnum::GetByID($enumId);
            if (is_array($enum) && (string)($enum['XML_ID'] ?? '') !== '') {
                return (string)$enum['XML_ID'];
            }
        }

        $value = $property['VALUE'] ?? null;
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private static function extractPositiveInt(mixed $value): ?int
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $extracted = self::extractPositiveInt($item);
                if ($extracted !== null) {
                    return $extracted;
                }
            }

            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private static function renderRow(string $inputName, int $propertyId, int $index, string $rowKey, array $answer, array $questions, array $results): string
    {
        $prefix = $inputName . '[rows][' . $index . ']';
        $rowKey = self::sanitizeRowKey($rowKey);
        $titleNumber = $index + 1;
        $html = '<div class="kk-quiz-answers__item">';
        $html .= '<input type="hidden" name="' . htmlspecialcharsbx($prefix . '[row_key]') . '" value="' . htmlspecialcharsbx($rowKey) . '">';
        $html .= '<div class="kk-quiz-answers__item-head">';
        $html .= '<strong data-kk-quiz-answer-title="Y">Ответ #' . htmlspecialcharsbx((string)$titleNumber) . '</strong>';
        $html .= '<button type="button" class="adm-btn kk-quiz-answers__delete" data-kk-quiz-answer-delete="Y">Удалить ответ</button>';
        $html .= '</div>';

        $html .= '<div class="kk-quiz-answers__section">';
        $html .= '<div class="kk-quiz-answers__section-title">Основное</div>';
        $html .= '<div class="kk-quiz-answers__grid">';
        $html .= '<label class="kk-quiz-answers__field kk-quiz-answers__field--checkbox"><span>Активен</span><input type="checkbox" name="' . htmlspecialcharsbx($prefix . '[active]') . '" value="Y"' . ($answer['active'] === 'Y' ? ' checked' : '') . '></label>';
        $html .= '<label class="kk-quiz-answers__field"><span>Сортировка</span><input type="number" name="' . htmlspecialcharsbx($prefix . '[sort]') . '" value="' . htmlspecialcharsbx((string)$answer['sort']) . '"></label>';
        $html .= '<label class="kk-quiz-answers__field kk-quiz-answers__field--wide"><span>Текст ответа</span><input type="text" name="' . htmlspecialcharsbx($prefix . '[text]') . '" value="' . htmlspecialcharsbx($answer['text']) . '"></label>';
        $html .= '<label class="kk-quiz-answers__field"><span>Код ответа</span><input type="text" name="' . htmlspecialcharsbx($prefix . '[code]') . '" value="' . htmlspecialcharsbx($answer['code']) . '"></label>';
        $html .= '</div></div>';

        $html .= '<div class="kk-quiz-answers__section">';
        $html .= '<div class="kk-quiz-answers__section-title">Картинка</div>';
        $html .= self::renderImageInput($prefix, $propertyId, $rowKey, $answer);
        $html .= '</div>';

        $html .= '<div class="kk-quiz-answers__section">';
        $html .= '<div class="kk-quiz-answers__section-title">Логика</div>';
        $html .= '<div class="kk-quiz-answers__grid">';
        $html .= '<label class="kk-quiz-answers__field"><span>Следующий вопрос</span>' . self::renderSelect($prefix . '[next_question_id]', $answer['next_question_id'], $questions) . '</label>';
        $html .= '<label class="kk-quiz-answers__field"><span>Финальный результат</span>' . self::renderSelect($prefix . '[result_id]', $answer['result_id'], $results) . '</label>';
        $html .= '<label class="kk-quiz-answers__field"><span>Результат для начисления баллов</span>' . self::renderSelect($prefix . '[score_result_id]', $answer['score_result_id'], $results) . '</label>';
        $html .= '<label class="kk-quiz-answers__field"><span>Баллы</span><input type="number" name="' . htmlspecialcharsbx($prefix . '[score_value]') . '" value="' . htmlspecialcharsbx((string)$answer['score_value']) . '"></label>';
        $html .= '</div></div>';

        $html .= '<div class="kk-quiz-answers__section">';
        $html .= '<label class="kk-quiz-answers__field"><span>Короткое описание</span><input type="text" name="' . htmlspecialcharsbx($prefix . '[description]') . '" value="' . htmlspecialcharsbx($answer['description']) . '"></label>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function renderImageInput(string $prefix, int $propertyId, string $rowKey, array $answer): string
    {
        $imageId = (int)($answer['image_id'] ?? 0);
        $fileInputName = self::FILE_FIELD_NAME . '[' . $propertyId . '][' . $rowKey . ']';
        $html = '<input type="hidden" name="' . htmlspecialcharsbx($prefix . '[image_id]') . '" value="' . ($imageId > 0 ? htmlspecialcharsbx((string)$imageId) : '') . '">';
        $html .= '<div class="kk-quiz-answers__image-layout">';

        if ($imageId > 0 && class_exists('CFile')) {
            $path = \CFile::GetPath($imageId);
            if (is_string($path) && $path !== '') {
                $html .= '<a class="kk-quiz-answers__preview" href="' . htmlspecialcharsbx($path) . '" target="_blank" rel="noopener">';
                $html .= '<img src="' . htmlspecialcharsbx($path) . '" alt="">';
                $html .= '<span>Текущий ID файла: ' . htmlspecialcharsbx((string)$imageId) . '</span></a>';
            } else {
                $html .= '<div class="kk-quiz-answers__image-missing">Файл #' . htmlspecialcharsbx((string)$imageId) . ' не найден</div>';
            }
        } else {
            $html .= '<div class="kk-quiz-answers__image-empty">Изображение не выбрано</div>';
        }

        $html .= '<label class="kk-quiz-answers__field"><span>Загрузить новое изображение</span>';
        $html .= '<input type="file" name="' . htmlspecialcharsbx($fileInputName) . '" accept="image/jpeg,image/png,image/webp,image/gif">';
        $html .= '<small>JPG, PNG, WEBP или GIF, до 5 МБ. Новый файл заменит текущую привязку, старый файл не удаляется.</small>';
        $html .= '</label>';
        $html .= '<label class="kk-quiz-answers__field kk-quiz-answers__field--checkbox"><input type="checkbox" name="' . htmlspecialcharsbx($prefix . '[delete_image]') . '" value="Y"><span>Удалить картинку</span></label>';
        $html .= '<details class="kk-quiz-answers__image-fallback"><summary>Служебно: указать ID файла вручную</summary>';
        $html .= '<label class="kk-quiz-answers__field"><span>ID файла</span><input type="number" name="' . htmlspecialcharsbx($prefix . '[image_id_manual]') . '" value="' . ($imageId > 0 ? htmlspecialcharsbx((string)$imageId) : '') . '" placeholder="ID файла"></label>';
        $html .= '</details>';
        $html .= '</div>';

        return $html;
    }

    private static function renderSelect(string $name, ?int $selectedValue, array $options): string
    {
        $html = '<select name="' . htmlspecialcharsbx($name) . '">';
        $html .= '<option value="">—</option>';

        foreach ($options as $id => $title) {
            $selected = ((int)$id === (int)$selectedValue) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialcharsbx((string)$id) . '"' . $selected . '>' . htmlspecialcharsbx($title) . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    private static function renderScript(string $rootId, string $inputName, int $propertyId, array $questions, array $results): string
    {
        $template = self::renderRow($inputName, $propertyId, 0, '__ROW_KEY__', self::getDefaultRow(), $questions, $results);

        return '(() => {'
            . 'const root = document.getElementById(' . self::json($rootId) . ');'
            . 'if (!root) return;'
            . 'const form = root.closest("form");'
            . 'if (form) { form.setAttribute("enctype", "multipart/form-data"); }'
            . 'const items = root.querySelector(".kk-quiz-answers__items");'
            . 'const addButton = root.querySelector(".kk-quiz-answers__add");'
            . 'const template = ' . self::json($template) . ';'
            . 'const getNextIndex = () => items.querySelectorAll(".kk-quiz-answers__item").length + Date.now();'
            . 'const renumber = () => {'
            . 'items.querySelectorAll("[data-kk-quiz-answer-title]").forEach((title, index) => { title.textContent = `Ответ #${index + 1}`; });'
            . '};'
            . 'addButton.addEventListener("click", () => {'
            . 'const nextIndex = getNextIndex();const rowKey = `new_${nextIndex}`;items.insertAdjacentHTML("beforeend", template.split("[rows][0]").join(`[rows][${nextIndex}]`).split("__ROW_KEY__").join(rowKey));'
            . 'renumber();'
            . '});'
            . 'root.addEventListener("click", (event) => {'
            . 'if (event.target.matches("[data-kk-quiz-answer-delete]")) {'
            . 'event.target.closest(".kk-quiz-answers__item").remove();'
            . 'renumber();'
            . '}'
            . '});'
            . 'renumber();'
            . '})();';
    }

    private static function renderStyles(string $rootId): string
    {
        $selector = '#' . $rootId;

        return '<style>'
            . $selector . '.kk-quiz-answers{max-width:980px;}'
            . $selector . ' .kk-quiz-answers__items{display:flex;flex-direction:column;gap:12px;}'
            . $selector . ' .kk-quiz-answers__item{box-sizing:border-box;border:1px solid #d7dfe5;border-radius:6px;background:#fff;padding:12px;max-width:100%;}'
            . $selector . ' .kk-quiz-answers__item-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;}'
            . $selector . ' .kk-quiz-answers__section{margin-top:10px;}'
            . $selector . ' .kk-quiz-answers__section-title{font-weight:bold;margin-bottom:6px;color:#4b5b68;}'
            . $selector . ' .kk-quiz-answers__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;}'
            . $selector . ' .kk-quiz-answers__field{display:flex;flex-direction:column;gap:4px;min-width:0;}'
            . $selector . ' .kk-quiz-answers__field span{font-size:12px;color:#4b5b68;}'
            . $selector . ' .kk-quiz-answers__field input[type="text"],'
            . $selector . ' .kk-quiz-answers__field input[type="number"],'
            . $selector . ' .kk-quiz-answers__field input[type="file"],'
            . $selector . ' .kk-quiz-answers__field select{box-sizing:border-box;width:100%;max-width:100%;}'
            . $selector . ' .kk-quiz-answers__field--checkbox{flex-direction:row;align-items:center;gap:8px;}'
            . $selector . ' .kk-quiz-answers__field--wide{grid-column:span 2;}'
            . $selector . ' .kk-quiz-answers__image-layout{display:grid;grid-template-columns:minmax(120px,180px) minmax(220px,1fr);gap:10px;align-items:start;}'
            . $selector . ' .kk-quiz-answers__field small{line-height:1.3;color:#6a737b;}'
            . $selector . ' .kk-quiz-answers__preview{display:inline-flex;flex-direction:column;align-items:flex-start;gap:6px;}'
            . $selector . ' .kk-quiz-answers__preview img{display:block;max-width:160px;max-height:100px;border:1px solid #d7dfe5;border-radius:4px;object-fit:contain;background:#f8fafc;}'
            . $selector . ' .kk-quiz-answers__image-empty,' . $selector . ' .kk-quiz-answers__image-missing{box-sizing:border-box;min-height:72px;padding:12px;border:1px dashed #c8d1d8;border-radius:4px;color:#6a737b;background:#f8fafc;}'
            . $selector . ' .kk-quiz-answers__image-fallback{grid-column:1/-1;}'
            . $selector . ' .kk-quiz-answers__actions{margin-top:12px;}'
            . '@media (max-width: 760px){' . $selector . ' .kk-quiz-answers__field--wide{grid-column:auto;}' . $selector . ' .kk-quiz-answers__item-head{align-items:flex-start;flex-direction:column;}' . $selector . ' .kk-quiz-answers__image-layout{grid-template-columns:1fr;}' . '}'
            . '</style>';
    }

    private static function getUploadedFile(int $propertyId, string $rowKey): ?array
    {
        $files = $_FILES[self::FILE_FIELD_NAME] ?? null;
        if (!is_array($files) || $propertyId <= 0 || $rowKey === '') {
            return null;
        }

        $name = $files['name'][$propertyId][$rowKey] ?? null;
        $tmpName = $files['tmp_name'][$propertyId][$rowKey] ?? null;
        $type = $files['type'][$propertyId][$rowKey] ?? '';
        $size = (int)($files['size'][$propertyId][$rowKey] ?? 0);
        $error = (int)($files['error'][$propertyId][$rowKey] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return [
            'name' => is_string($name) ? $name : '',
            'tmp_name' => is_string($tmpName) ? $tmpName : '',
            'type' => is_string($type) ? $type : '',
            'size' => $size,
            'error' => $error,
        ];
    }

    private static function saveUploadedImage(int $propertyId, string $rowKey): ?int
    {
        $file = self::getUploadedFile($propertyId, $rowKey);
        if ($file === null || self::validateImageFile($file) !== null || !class_exists('CFile')) {
            return null;
        }

        $fileId = (int)\CFile::SaveFile($file, 'kk.quiz/answers');

        return $fileId > 0 ? $fileId : null;
    }

    private static function getFileValidationErrors(int $propertyId, mixed $rawValue): array
    {
        if ($propertyId <= 0 || !is_array($rawValue)) {
            return [];
        }

        $rows = $rawValue['rows'] ?? $rawValue;
        if (!is_array($rows)) {
            return [];
        }

        $errors = [];
        foreach ($rows as $rowKey => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (self::sanitizeText($row['text'] ?? $row['TEXT'] ?? '') === '' || (($row['delete_image'] ?? 'N') === 'Y')) {
                continue;
            }

            $submittedRowKey = self::sanitizeRowKey((string)($row['row_key'] ?? $rowKey));
            $file = self::getUploadedFile($propertyId, $submittedRowKey);
            if ($file === null) {
                continue;
            }

            $error = self::validateImageFile($file);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private static function validateImageFile(array $file): ?string
    {
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            return 'Не удалось загрузить изображение ответа. Код ошибки загрузки: ' . (int)$file['error'];
        }

        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::MAX_IMAGE_SIZE) {
            return 'Размер изображения ответа должен быть не больше 5 МБ.';
        }

        $extension = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            return 'Изображение ответа должно быть в формате jpg, jpeg, png, webp или gif.';
        }

        $tmpName = (string)$file['tmp_name'];
        if ($tmpName === '' || !is_file($tmpName) || @getimagesize($tmpName) === false) {
            return 'Загруженный файл ответа не является изображением.';
        }

        return null;
    }

    private static function sanitizeRowKey(string $rowKey): string
    {
        $rowKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $rowKey) ?? '';

        return $rowKey !== '' ? $rowKey : 'row_' . uniqid('', false);
    }

    private static function json(string $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
