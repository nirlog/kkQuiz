<?php

declare(strict_types=1);

namespace Kk\Quiz\Iblock\Property;

final class QuizAnswersProperty
{
    public const USER_TYPE = 'kk_quiz_answers';

    private const FILE_FIELD_NAME = 'kk_quiz_answer_image';
    private const MAX_IMAGE_SIZE = 5242880;
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
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
        $propertyId = (int)($property['ID'] ?? 0);
        $iblockId = (int)($property['IBLOCK_ID'] ?? 0);
        $sectionId = self::getCurrentSectionId($property);
        $fieldName = (string)($control['VALUE'] ?? 'VALUE');
        $answers = self::decodeAnswers($value['VALUE'] ?? []);
        $questionOptions = self::getElementOptions($iblockId, self::ENTITY_TYPE_QUESTION, $sectionId);
        $resultOptions = self::getElementOptions($iblockId, self::ENTITY_TYPE_RESULT, $sectionId);

        if ($answers === []) {
            $answers[] = self::getEmptyAnswer();
        }

        $html = self::renderStyles();
        $html .= '<div class="kk-quiz-answers" data-property-id="' . $propertyId . '" data-field-name="' . self::e($fieldName) . '">';
        $html .= '<input type="hidden" name="' . self::e($fieldName) . '[rows_present]" value="Y">';
        $html .= '<div class="kk-quiz-answers__list">';

        foreach ($answers as $index => $answer) {
            $rowKey = self::getRowKey($answer, $index);
            $html .= self::renderAnswerCard($propertyId, $fieldName, $rowKey, $index + 1, $answer, $questionOptions, $resultOptions);
        }

        $html .= '</div>';
        $html .= '<button type="button" class="adm-btn kk-quiz-answers__add">+ Добавить ответ</button>';
        $html .= '</div>';
        $html .= self::renderScript($questionOptions, $resultOptions);

        return $html;
    }

    public static function convertToDb(array $property, array $value): array
    {
        $propertyId = (int)($property['ID'] ?? 0);
        $iblockId = (int)($property['IBLOCK_ID'] ?? 0);
        $sectionId = self::getCurrentSectionId($property);
        $rows = self::decodeSubmittedRows($value['VALUE'] ?? []);
        $answers = [];

        foreach ($rows as $rowKey => $row) {
            $text = trim(self::sanitizeString($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $imageId = self::toNullableInt($row['image_id'] ?? null);
            if (($row['delete_image'] ?? 'N') === 'Y') {
                $imageId = null;
            } else {
                $uploadedImageId = self::saveUploadedImage($propertyId, (string)$rowKey);
                if ($uploadedImageId !== null) {
                    $imageId = $uploadedImageId;
                }
            }

            $answers[] = [
                'active' => (($row['active'] ?? 'N') === 'Y') ? 'Y' : 'N',
                'sort' => (int)($row['sort'] ?? 100),
                'text' => $text,
                'code' => self::sanitizeString($row['code'] ?? ''),
                'image_id' => $imageId,
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
        $propertyId = (int)($property['ID'] ?? 0);
        $rows = self::decodeSubmittedRows($value['VALUE'] ?? []);
        $errors = [];

        foreach ($rows as $rowKey => $row) {
            if (trim(self::sanitizeString($row['text'] ?? '')) === '') {
                continue;
            }

            $file = self::getUploadedFile($propertyId, (string)$rowKey);
            if ($file === null || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $error = self::validateUploadedImage($file);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
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

        $elements = \CIBlockElement::GetList([], $filter, false, ['nTopCount' => 1], ['ID', 'NAME']);
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

    private static function renderAnswerCard(int $propertyId, string $fieldName, string $rowKey, int $number, array $answer, array $questionOptions, array $resultOptions): string
    {
        $prefix = $fieldName . '[' . $rowKey . ']';
        $imageId = self::toNullableInt($answer['image_id'] ?? null);
        $imageSrc = self::getFilePath($imageId);
        $fileName = self::FILE_FIELD_NAME . '[' . $propertyId . '][' . $rowKey . ']';
        $html = '<div class="kk-quiz-answers__item" data-row-key="' . self::e($rowKey) . '">';
        $html .= '<div class="kk-quiz-answers__header"><strong>Ответ #' . $number . '</strong><button type="button" class="adm-btn kk-quiz-answers__remove">Удалить ответ</button></div>';
        $html .= '<input type="hidden" name="' . self::e($prefix . '[row_key]') . '" value="' . self::e($rowKey) . '">';
        $html .= '<div class="kk-quiz-answers__section"><h4>Основное</h4><div class="kk-quiz-answers__grid">';
        $html .= self::field('Активен', '<label><input type="checkbox" name="' . self::e($prefix . '[active]') . '" value="Y"' . (((string)($answer['active'] ?? 'Y') === 'Y') ? ' checked' : '') . '> Да</label>');
        $html .= self::field('Сортировка', '<input type="number" name="' . self::e($prefix . '[sort]') . '" value="' . self::e((string)($answer['sort'] ?? 100)) . '">');
        $html .= self::field('Текст ответа', '<input type="text" name="' . self::e($prefix . '[text]') . '" value="' . self::e((string)($answer['text'] ?? '')) . '">');
        $html .= self::field('Код ответа', '<input type="text" name="' . self::e($prefix . '[code]') . '" value="' . self::e((string)($answer['code'] ?? '')) . '">');
        $html .= '</div></div>';
        $html .= '<div class="kk-quiz-answers__section"><h4>Картинка</h4><div class="kk-quiz-answers__grid">';
        $preview = $imageSrc !== null ? '<img src="' . self::e($imageSrc) . '" alt="" class="kk-quiz-answers__preview">' : '<span class="kk-quiz-answers__muted">Картинка не выбрана</span>';
        $html .= self::field('Превью', $preview);
        $html .= self::field('Загрузить новое изображение', '<input type="file" name="' . self::e($fileName) . '" accept="image/jpeg,image/png,image/webp,image/gif">');
        $html .= self::field('Удалить картинку', '<label><input type="checkbox" name="' . self::e($prefix . '[delete_image]') . '" value="Y"> Удалить привязку</label>');
        $html .= self::field('Текущий ID файла', '<input type="number" name="' . self::e($prefix . '[image_id]') . '" value="' . self::e((string)($imageId ?? '')) . '"><small>Fallback: ID файла из медиабиблиотеки/файлов Битрикса</small>');
        $html .= '</div></div>';
        $html .= '<div class="kk-quiz-answers__section"><h4>Логика</h4><div class="kk-quiz-answers__grid">';
        $html .= self::field('Следующий вопрос', self::renderSelect($prefix . '[next_question_id]', self::toNullableInt($answer['next_question_id'] ?? null), $questionOptions));
        $html .= self::field('Финальный результат', self::renderSelect($prefix . '[result_id]', self::toNullableInt($answer['result_id'] ?? null), $resultOptions));
        $html .= self::field('Результат для начисления баллов', self::renderSelect($prefix . '[score_result_id]', self::toNullableInt($answer['score_result_id'] ?? null), $resultOptions));
        $html .= self::field('Баллы', '<input type="number" name="' . self::e($prefix . '[score_value]') . '" value="' . self::e((string)($answer['score_value'] ?? 0)) . '">');
        $html .= '</div></div>';
        $html .= '<div class="kk-quiz-answers__section"><h4>Короткое описание</h4><textarea name="' . self::e($prefix . '[description]') . '">' . self::e((string)($answer['description'] ?? '')) . '</textarea></div>';
        $html .= '</div>';

        return $html;
    }

    private static function renderStyles(): string
    {
        return '<style>.kk-quiz-answers{max-width:100%;}.kk-quiz-answers *{box-sizing:border-box}.kk-quiz-answers__item{border:1px solid #d5d5d5;background:#fff;border-radius:6px;padding:12px;margin:0 0 12px}.kk-quiz-answers__header{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.kk-quiz-answers__section{margin-top:12px}.kk-quiz-answers__section h4{margin:0 0 8px}.kk-quiz-answers__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.kk-quiz-answers__field label{display:block;font-weight:600;margin-bottom:4px}.kk-quiz-answers input[type=text],.kk-quiz-answers input[type=number],.kk-quiz-answers select,.kk-quiz-answers textarea{width:100%;max-width:100%}.kk-quiz-answers textarea{min-height:70px}.kk-quiz-answers__preview{display:block;max-width:140px;max-height:90px}.kk-quiz-answers__muted,.kk-quiz-answers small{display:block;color:#777;margin-top:4px}.kk-quiz-answers__add{margin-top:8px}</style>';
    }

    private static function renderScript(array $questionOptions, array $resultOptions): string
    {
        $questionOptionsJson = json_encode($questionOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $resultOptionsJson = json_encode($resultOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $template = <<<'HTML'
<script>
(function () {
    const roots = document.querySelectorAll('.kk-quiz-answers');
    const questionOptions = __QUESTION_OPTIONS__;
    const resultOptions = __RESULT_OPTIONS__;
    const fileFieldName = '__FILE_FIELD_NAME__';
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char] || char));
    const renderSelect = (name, items) => {
        let html = `<select name="${escapeHtml(name)}"><option value="">(не выбрано)</option>`;
        Object.keys(items).forEach((id) => {
            html += `<option value="${escapeHtml(id)}">${escapeHtml(items[id])}</option>`;
        });
        return `${html}</select>`;
    };

    roots.forEach((root) => {
        const list = root.querySelector('.kk-quiz-answers__list');
        const add = root.querySelector('.kk-quiz-answers__add');
        const propertyId = root.dataset.propertyId || '0';
        const fieldName = root.dataset.fieldName || 'VALUE';
        const renumber = () => {
            list.querySelectorAll('.kk-quiz-answers__item').forEach((item, index) => {
                const title = item.querySelector('.kk-quiz-answers__header strong');
                if (title) {
                    title.textContent = `Ответ #${index + 1}`;
                }
            });
        };

        root.addEventListener('click', (event) => {
            if (event.target.classList.contains('kk-quiz-answers__remove')) {
                event.preventDefault();
                event.target.closest('.kk-quiz-answers__item').remove();
                renumber();
            }
        });

        if (add) {
            add.addEventListener('click', () => {
                const key = `new_${Date.now()}_${Math.floor(Math.random() * 10000)}`;
                const prefix = `${fieldName}[${key}]`;
                const fileName = `${fileFieldName}[${propertyId}][${key}]`;
                const item = document.createElement('div');
                item.className = 'kk-quiz-answers__item';
                item.dataset.rowKey = key;
                item.innerHTML = `<div class="kk-quiz-answers__header"><strong>Ответ</strong><button type="button" class="adm-btn kk-quiz-answers__remove">Удалить ответ</button></div><input type="hidden" name="${escapeHtml(prefix)}[row_key]" value="${escapeHtml(key)}"><div class="kk-quiz-answers__section"><h4>Основное</h4><div class="kk-quiz-answers__grid"><div class="kk-quiz-answers__field"><label>Активен</label><label><input type="checkbox" name="${escapeHtml(prefix)}[active]" value="Y" checked> Да</label></div><div class="kk-quiz-answers__field"><label>Сортировка</label><input type="number" name="${escapeHtml(prefix)}[sort]" value="100"></div><div class="kk-quiz-answers__field"><label>Текст ответа</label><input type="text" name="${escapeHtml(prefix)}[text]" value=""></div><div class="kk-quiz-answers__field"><label>Код ответа</label><input type="text" name="${escapeHtml(prefix)}[code]" value=""></div></div></div><div class="kk-quiz-answers__section"><h4>Картинка</h4><div class="kk-quiz-answers__grid"><div class="kk-quiz-answers__field"><label>Превью</label><span class="kk-quiz-answers__muted">Картинка не выбрана</span></div><div class="kk-quiz-answers__field"><label>Загрузить новое изображение</label><input type="file" name="${escapeHtml(fileName)}" accept="image/jpeg,image/png,image/webp,image/gif"></div><div class="kk-quiz-answers__field"><label>Удалить картинку</label><label><input type="checkbox" name="${escapeHtml(prefix)}[delete_image]" value="Y"> Удалить привязку</label></div><div class="kk-quiz-answers__field"><label>Текущий ID файла</label><input type="number" name="${escapeHtml(prefix)}[image_id]" value=""><small>Fallback: ID файла из медиабиблиотеки/файлов Битрикса</small></div></div></div><div class="kk-quiz-answers__section"><h4>Логика</h4><div class="kk-quiz-answers__grid"><div class="kk-quiz-answers__field"><label>Следующий вопрос</label>${renderSelect(`${prefix}[next_question_id]`, questionOptions)}</div><div class="kk-quiz-answers__field"><label>Финальный результат</label>${renderSelect(`${prefix}[result_id]`, resultOptions)}</div><div class="kk-quiz-answers__field"><label>Результат для начисления баллов</label>${renderSelect(`${prefix}[score_result_id]`, resultOptions)}</div><div class="kk-quiz-answers__field"><label>Баллы</label><input type="number" name="${escapeHtml(prefix)}[score_value]" value="0"></div></div></div><div class="kk-quiz-answers__section"><h4>Короткое описание</h4><textarea name="${escapeHtml(prefix)}[description]"></textarea></div>`;
                list.appendChild(item);
                renumber();
            });
        }

        renumber();
    });
})();
</script>
HTML;

        return strtr($template, [
            '__QUESTION_OPTIONS__' => $questionOptionsJson,
            '__RESULT_OPTIONS__' => $resultOptionsJson,
            '__FILE_FIELD_NAME__' => self::FILE_FIELD_NAME,
        ]);
    }

    private static function field(string $label, string $control): string
    {
        return '<div class="kk-quiz-answers__field"><label>' . self::e($label) . '</label>' . $control . '</div>';
    }

    private static function renderSelect(string $name, ?int $selectedId, array $options): string
    {
        $html = '<select name="' . self::e($name) . '"><option value="">(не выбрано)</option>';
        foreach ($options as $id => $label) {
            $id = (int)$id;
            $html .= '<option value="' . $id . '"' . (($selectedId === $id) ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }

        return $html . '</select>';
    }

    private static function saveUploadedImage(int $propertyId, string $rowKey): ?int
    {
        $file = self::getUploadedFile($propertyId, $rowKey);
        if ($file === null || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || self::validateUploadedImage($file) !== null || !class_exists('CFile')) {
            return null;
        }

        $fileId = \CFile::SaveFile($file, 'kk_quiz_answers');

        return self::toNullableInt($fileId);
    }

    private static function validateUploadedImage(array $file): ?string
    {
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return 'Ошибка загрузки изображения варианта ответа.';
        }
        if ((int)($file['size'] ?? 0) > self::MAX_IMAGE_SIZE) {
            return 'Размер изображения варианта ответа не должен превышать 5 МБ.';
        }
        $extension = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            return 'Допустимые форматы изображения варианта ответа: jpg, jpeg, png, webp, gif.';
        }

        return null;
    }

    private static function getUploadedFile(int $propertyId, string $rowKey): ?array
    {
        $files = $_FILES[self::FILE_FIELD_NAME] ?? null;
        if (!is_array($files) || !isset($files['name'][$propertyId][$rowKey])) {
            return null;
        }

        return [
            'name' => $files['name'][$propertyId][$rowKey],
            'type' => $files['type'][$propertyId][$rowKey] ?? '',
            'tmp_name' => $files['tmp_name'][$propertyId][$rowKey] ?? '',
            'error' => $files['error'][$propertyId][$rowKey] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$propertyId][$rowKey] ?? 0,
        ];
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
        if (isset($value['rows_present'])) {
            unset($value['rows_present']);
        }

        return array_is_list($value) ? array_values(array_filter($value, static fn (mixed $row): bool => is_array($row))) : [$value];
    }

    private static function decodeSubmittedRows(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        unset($value['rows_present']);
        if (array_is_list($value)) {
            $rows = [];
            foreach ($value as $index => $row) {
                if (is_array($row)) {
                    $rows[self::getRowKey($row, $index)] = $row;
                }
            }

            return $rows;
        }

        return array_filter($value, static fn (mixed $row): bool => is_array($row));
    }

    private static function getRowKey(array $answer, int $index): string
    {
        $rowKey = (string)($answer['row_key'] ?? '');

        return $rowKey !== '' ? $rowKey : 'row_' . $index;
    }

    private static function getEmptyAnswer(): array
    {
        return ['active' => 'Y', 'sort' => 100, 'text' => '', 'code' => '', 'image_id' => null, 'description' => '', 'next_question_id' => null, 'result_id' => null, 'score_result_id' => null, 'score_value' => 0];
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

    private static function toNullableInt(mixed $value): ?int
    {
        if (is_array($value)) {
            return self::extractPositiveInt($value);
        }
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private static function getFilePath(?int $fileId): ?string
    {
        if ($fileId === null || !class_exists('CFile')) {
            return null;
        }
        $path = \CFile::GetPath($fileId);

        return is_string($path) && $path !== '' ? $path : null;
    }

    private static function sanitizeString(mixed $value): string
    {
        return trim(strip_tags(is_scalar($value) ? (string)$value : ''));
    }

    private static function e(string $value): string
    {
        return function_exists('htmlspecialcharsbx') ? htmlspecialcharsbx($value) : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
