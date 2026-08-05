<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
global $APPLICATION, $USER;
if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) { $APPLICATION->AuthForm('Доступ запрещён'); }
if (!Loader::includeModule('kk.quiz') || !Loader::includeModule('iblock')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage('Модуль kk.quiz или iblock не установлен.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; return;
}
$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$id = (int)($_REQUEST['ID'] ?? 0); $sectionId = (int)($_REQUEST['SECTION_ID'] ?? 0);
$back = in_array((string)($_REQUEST['back'] ?? 'list'), ['schema','list','edit'], true) ? (string)$_REQUEST['back'] : 'list';
$iblock = CIBlock::GetList([], ['TYPE'=>Installer::IBLOCK_TYPE_ID,'CODE'=>Installer::QUIZZES_IBLOCK_CODE])->Fetch();
$iblockId = (int)($iblock['ID'] ?? 0);
$elementObject = $id && $sectionId ? CIBlockElement::GetList([], ['ID'=>$id,'IBLOCK_ID'=>$iblockId,'SECTION_ID'=>$sectionId,'INCLUDE_SUBSECTIONS'=>'N'], false, false, ['*'])->GetNextElement() : false;
$fields = $elementObject ? $elementObject->GetFields() : []; $properties = $elementObject ? $elementObject->GetProperties() : [];
$type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE_XML_ID'] ?? $properties['KK_ENTITY_TYPE']['VALUE'] ?? ''));
$valid = $elementObject && in_array($type, ['QUESTION','RESULT'], true);
$schemaUrl = 'kk_quiz_schema.php?' . http_build_query(['ID'=>$sectionId,'lang'=>$lang]);
$listUrl = 'kk_quiz_quizzes.php?' . http_build_query(['lang'=>$lang]);
$returnUrl = $back === 'schema' ? $schemaUrl : $listUrl;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel']) && check_bitrix_sessid()) { LocalRedirect($returnUrl); }
$APPLICATION->SetTitle($type === 'RESULT' ? 'KK Quiz — редактирование результата' : 'KK Quiz — редактирование вопроса');
$errors = [];
$getPropertyRawValue = static function (array $properties, string $code): mixed {
    return $properties[$code]['VALUE'] ?? null;
};
$getPropertyScalarValue = static function (array $properties, string $code) use ($getPropertyRawValue): string {
    $value = $getPropertyRawValue($properties, $code);
    if (is_array($value)) {
        $value = reset($value);
    }
    return is_scalar($value) ? (string)$value : '';
};
$getPropertyEnumXmlId = static function (array $properties, string $code): string {
    $value = $properties[$code]['VALUE_XML_ID'] ?? '';
    if (is_array($value)) {
        $value = reset($value);
    }
    return is_scalar($value) ? (string)$value : '';
};
$getPropertyEnumId = static function (array $properties, string $code): int {
    $value = $properties[$code]['VALUE_ENUM_ID'] ?? 0;
    if (is_array($value)) {
        $value = reset($value);
    }
    return (int)$value;
};
$getPropertyMultipleValues = static function (array $properties, string $code) use ($getPropertyRawValue): array {
    $value = $getPropertyRawValue($properties, $code);
    if ($value === null || $value === '') {
        return [];
    }
    return array_values(array_filter(is_array($value) ? $value : [$value], static fn (mixed $item): bool => $item !== null && $item !== ''));
};
$getIblockPropertyEnumOptions = static function (int $iblockId, string $propertyCode): array {
    $options = [];
    $enumResult = CIBlockPropertyEnum::GetList(
        ['SORT' => 'ASC', 'ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
    );
    while ($enum = $enumResult->Fetch()) {
        $xmlId = (string)($enum['XML_ID'] ?? '');
        if ($xmlId === '') {
            $xmlId = (string)$enum['ID'];
        }
        $options[$xmlId] = [
            'id' => (int)$enum['ID'],
            'xml_id' => $xmlId,
            'value' => (string)$enum['VALUE'],
        ];
    }
    return $options;
};
$enumFields = $type === 'QUESTION'
    ? ['KK_QUESTION_TYPE', 'KK_DISPLAY_TEMPLATE', 'KK_IMAGE_RATIO', 'KK_IMAGE_FIT', 'KK_IS_REQUIRED', 'KK_ALLOW_CUSTOM_ANSWER']
    : ['KK_RESULT_CTA_TARGET', 'KK_RESULT_SECONDARY_CTA_TARGET', 'KK_RESULT_SHOW_FORM', 'KK_RESULT_VIDEO_POSITION'];
$enumOptions = [];
$enumLoadFailed = false;
foreach ($enumFields as $enumField) {
    $enumOptions[$enumField] = $getIblockPropertyEnumOptions($iblockId, $enumField);
    if ($enumOptions[$enumField] === []) {
        $enumLoadFailed = true;
    }
}
$getCurrentEnumXmlId = static function (string $code) use ($properties, $enumOptions, $getPropertyEnumXmlId, $getPropertyEnumId): string {
    $xmlId = $getPropertyEnumXmlId($properties, $code);
    if ($xmlId !== '') {
        return $xmlId;
    }
    $enumId = $getPropertyEnumId($properties, $code);
    foreach ($enumOptions[$code] ?? [] as $option) {
        if ($option['id'] === $enumId) {
            return $option['xml_id'];
        }
    }
    return '';
};
$normalizePostedPropertyValue = static function (string $code, mixed $value) use ($properties, $enumOptions): mixed {
    if (($properties[$code]['PROPERTY_TYPE'] ?? '') === 'L') {
        foreach ($enumOptions[$code] ?? [] as $option) {
            if ((string)$value === $option['xml_id'] || (string)$value === (string)$option['id']) {
                return $option['id'];
            }
        }
        return null;
    }
    if (($properties[$code]['MULTIPLE'] ?? 'N') === 'Y') {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        return array_values(array_filter(array_map('intval', (array)$value)));
    }
    return is_string($value) ? trim($value) : $value;
};
$urlValid = static fn (string $url): bool => $url === '' || (preg_match('/[\x00-\x1F\x7F]/', $url) !== 1 && ((str_starts_with($url, '/') && !str_starts_with($url, '//')) || preg_match('~^https?://[^\s]+$~i', $url) === 1));
$decodeAnswersValue = static function (mixed $value): array {
    $invalid = false;
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') return ['answers' => [], 'invalid' => false];
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) return ['answers' => [], 'invalid' => true];
        $value = $decoded;
    }
    if (!is_array($value)) return ['answers' => [], 'invalid' => $value !== null && $value !== ''];
    $rows = $value['rows'] ?? $value;
    if (!is_array($rows)) return ['answers' => [], 'invalid' => true];
    if (isset($rows['text']) || isset($rows['TEXT'])) $rows = [$rows];
    $expandedRows = [];
    foreach ($rows as $row) {
        if (is_string($row)) {
            $decoded = json_decode(trim($row), true);
            if (!is_array($decoded)) { $invalid = true; continue; }
            $row = $decoded['rows'] ?? $decoded;
        }
        if (!is_array($row)) { $invalid = true; continue; }
        if (isset($row['text']) || isset($row['TEXT'])) $expandedRows[] = $row;
        else foreach ($row as $nestedRow) {
            if (is_array($nestedRow)) $expandedRows[] = $nestedRow; else $invalid = true;
        }
    }
    $answers = [];
    foreach ($expandedRows as $row) {
        $read = static fn (string $key, mixed $default = ''): mixed => $row[$key] ?? $row[strtoupper($key)] ?? $default;
        $imageId = (int)$read('image_id', 0);
        $imageSrc = (string)$read('image_src');
        if ($imageId > 0 && $imageSrc === '') $imageSrc = (string)CFile::GetPath($imageId);
        $answers[] = [
            'active' => in_array($read('active', false), [true, 1, '1', 'Y'], true),
            'sort' => (int)$read('sort', 500), 'text' => (string)$read('text'),
            'code' => (string)$read('code'), 'description' => (string)$read('description'),
            'image_id' => $imageId, 'image_src' => $imageSrc,
            'next_question_id' => (int)$read('next_question_id', 0),
            'next_question_code' => trim((string)$read('next_question_code')),
            'result_id' => (int)$read('result_id', 0),
            'result_code' => trim((string)$read('result_code')),
            'score_result_id' => (int)$read('score_result_id', 0),
            'score_result_code' => trim((string)$read('score_result_code')),
            'score_value' => (int)$read('score_value', 0),
        ];
    }
    return ['answers' => $answers, 'invalid' => $invalid];
};
$rawAnswers = $getPropertyRawValue($properties, 'KK_ANSWERS');
$decodedAnswers = $decodeAnswersValue($rawAnswers);
$answerRows = $decodedAnswers['answers'];
$answersSourceNotEmpty = !(is_null($rawAnswers) || $rawAnswers === '' || $rawAnswers === []);
$answersDecodeInvalid = $type === 'QUESTION' && ($decodedAnswers['invalid'] || ($answersSourceNotEmpty && $answerRows === []));
$loadQuizElementOptions = static function (int $iblockId, int $sectionId, callable $getPropertyScalarValue): array {
    $options = ['questions'=>[], 'results'=>[], 'question_codes'=>[], 'result_codes'=>[]];
    $elementResult = CIBlockElement::GetList(
        ['SORT'=>'ASC', 'ID'=>'ASC'],
        ['IBLOCK_ID'=>$iblockId, 'SECTION_ID'=>$sectionId, 'INCLUDE_SUBSECTIONS'=>'N'],
        false,
        false,
        ['ID','IBLOCK_ID','CODE','NAME','ACTIVE','SORT']
    );
    while ($elementObject = $elementResult->GetNextElement()) {
        $optionFields = $elementObject->GetFields();
        $optionProperties = $elementObject->GetProperties();
        $entityType = '';
        foreach (['VALUE_XML_ID', 'VALUE', 'VALUE_ENUM'] as $typeValueKey) {
            $candidate = $optionProperties['KK_ENTITY_TYPE'][$typeValueKey] ?? '';
            if (is_array($candidate)) $candidate = reset($candidate);
            if (trim((string)$candidate) !== '') { $entityType = (string)$candidate; break; }
        }
        $entityType = strtoupper(trim($entityType));
        if (!in_array($entityType, ['QUESTION','RESULT'], true)) continue;
        $optionId = (int)$optionFields['ID'];
        $optionCode = trim((string)($optionFields['CODE'] ?? ''));
        $publicTitle = trim($getPropertyScalarValue($optionProperties, 'KK_PUBLIC_TITLE'));
        $title = $publicTitle !== '' ? $publicTitle : (string)$optionFields['NAME'];
        $label = $title . ' [ID ' . $optionId . ($optionCode !== '' ? ', code ' . $optionCode : '') . ']';
        $bucket = $entityType === 'QUESTION' ? 'questions' : 'results';
        $codeBucket = $entityType === 'QUESTION' ? 'question_codes' : 'result_codes';
        $options[$bucket][$optionId] = $label;
        if ($optionCode !== '') $options[$codeBucket][$optionCode] = $optionId;
    }
    return $options;
};
$getLinkedElementId = static function (array $properties, string $code): int {
    $value = $properties[$code]['VALUE'] ?? 0;
    if (is_array($value)) $value = reset($value);
    return is_scalar($value) ? max(0, (int)$value) : 0;
};
$items = $loadQuizElementOptions($iblockId, $sectionId, $getPropertyScalarValue);
$relationOptionsInvalid = $type === 'QUESTION' && ($items['questions'] === [] || $items['results'] === []);
$brokenAnswerTargets = false;
foreach ($answerRows as &$answerRow) {
    if ($answerRow['next_question_id'] <= 0 && $answerRow['next_question_code'] !== '') $answerRow['next_question_id'] = (int)($items['question_codes'][$answerRow['next_question_code']] ?? 0);
    if ($answerRow['result_id'] <= 0 && $answerRow['result_code'] !== '') $answerRow['result_id'] = (int)($items['result_codes'][$answerRow['result_code']] ?? 0);
    if ($answerRow['score_result_id'] <= 0 && $answerRow['score_result_code'] !== '') $answerRow['score_result_id'] = (int)($items['result_codes'][$answerRow['score_result_code']] ?? 0);
    $answerRow['target_warnings'] = [];
    if (($answerRow['next_question_id'] > 0 && !isset($items['questions'][$answerRow['next_question_id']])) || ($answerRow['next_question_id'] <= 0 && $answerRow['next_question_code'] !== '')) $answerRow['target_warnings'][] = 'Вопрос: ' . ($answerRow['next_question_code'] ?: $answerRow['next_question_id']);
    if (($answerRow['result_id'] > 0 && !isset($items['results'][$answerRow['result_id']])) || ($answerRow['result_id'] <= 0 && $answerRow['result_code'] !== '')) $answerRow['target_warnings'][] = 'Результат: ' . ($answerRow['result_code'] ?: $answerRow['result_id']);
    if (($answerRow['score_result_id'] > 0 && !isset($items['results'][$answerRow['score_result_id']])) || ($answerRow['score_result_id'] <= 0 && $answerRow['score_result_code'] !== '')) $answerRow['target_warnings'][] = 'Scoring: ' . ($answerRow['score_result_code'] ?: $answerRow['score_result_id']);
    if ($answerRow['target_warnings'] !== []) $brokenAnswerTargets = true;
}
unset($answerRow);
$defaultNextQuestionId = $getLinkedElementId($properties, 'KK_DEFAULT_NEXT_QUESTION');
$defaultResultId = $getLinkedElementId($properties, 'KK_DEFAULT_RESULT');
$brokenDefaultTargets = $type === 'QUESTION' && (($defaultNextQuestionId > 0 && !isset($items['questions'][$defaultNextQuestionId])) || ($defaultResultId > 0 && !isset($items['results'][$defaultResultId])));
$questionCodes = ['KK_QUESTION_TYPE', 'KK_DISPLAY_TEMPLATE', 'KK_IMAGE_RATIO', 'KK_IMAGE_FIT', 'KK_IS_REQUIRED', 'KK_PLACEHOLDER', 'KK_ALLOW_CUSTOM_ANSWER', 'KK_DEFAULT_NEXT_QUESTION', 'KK_DEFAULT_RESULT'];
$resultCodes = ['KK_RESULT_BADGE', 'KK_RESULT_SUMMARY', 'KK_RESULT_WHY_TEXT', 'KK_RESULT_FIT_TEXT', 'KK_RESULT_SPECS_TEXT', 'KK_RESULT_BUDGET_TEXT', 'KK_RESULT_NOTE_TEXT', 'KK_RESULT_CTA_TEXT', 'KK_RESULT_CTA_LINK', 'KK_RESULT_CTA_TARGET', 'KK_RESULT_SECONDARY_CTA_TEXT', 'KK_RESULT_SECONDARY_CTA_LINK', 'KK_RESULT_SECONDARY_CTA_TARGET', 'KK_RESULT_FORM_TITLE', 'KK_RESULT_FORM_INTRO', 'KK_RESULT_FORM_BUTTON_TEXT', 'KK_RESULT_SHOW_FORM', 'KK_RESULT_VIDEO_URL', 'KK_RESULT_VIDEO_TITLE', 'KK_RESULT_VIDEO_POSITION', 'KK_RESULT_CATALOG_SECTION', 'KK_RESULT_CATALOG_PRODUCTS', 'KK_RESULT_MIN_SCORE', 'KK_RESULT_MAX_SCORE', 'KK_RESULT_PRIORITY'];
$quizSection = CIBlockSection::GetList([], ['ID'=>$sectionId, 'IBLOCK_ID'=>$iblockId], false, ['ID','UF_KK_CATALOG_IBLOCK_IDS','UF_KK_CATALOG_IBLOCK_ID'])->Fetch();
$catalogIblockIds = [];
$catalogRawValues = array_values(array_filter((array)($quizSection['UF_KK_CATALOG_IBLOCK_IDS'] ?? []), static fn (mixed $value): bool => $value !== '' && $value !== null));
$userField = CUserTypeEntity::GetList([], ['ENTITY_ID'=>'IBLOCK_'.$iblockId.'_SECTION', 'FIELD_NAME'=>'UF_KK_CATALOG_IBLOCK_IDS'])->Fetch();
$catalogEnumsById = []; $catalogEnumsByXmlId = []; $catalogEnumsByValue = [];
if (is_array($userField)) {
    $enumResult = CUserFieldEnum::GetList([], ['USER_FIELD_ID'=>(int)$userField['ID']]);
    while ($catalogEnum = $enumResult->Fetch()) {
        $resolvedIblockId = (int)($catalogEnum['XML_ID'] ?? 0);
        $catalogEnumsById[(string)$catalogEnum['ID']] = $resolvedIblockId;
        $catalogEnumsByXmlId[(string)$catalogEnum['XML_ID']] = $resolvedIblockId;
        $catalogEnumsByValue[(string)$catalogEnum['VALUE']] = $resolvedIblockId;
    }
}
foreach ($catalogRawValues as $catalogRawValue) {
    $key = trim((string)$catalogRawValue);
    $resolvedIblockId = $catalogEnumsById[$key] ?? $catalogEnumsByXmlId[$key] ?? $catalogEnumsByValue[$key] ?? 0;
    if ($resolvedIblockId <= 0 && ctype_digit($key) && CIBlock::GetByID((int)$key)->Fetch()) $resolvedIblockId = (int)$key;
    if ($resolvedIblockId > 0) $catalogIblockIds[] = $resolvedIblockId;
}
$legacyCatalogIblockId = (int)($quizSection['UF_KK_CATALOG_IBLOCK_ID'] ?? 0);
if ($catalogIblockIds === [] && $legacyCatalogIblockId > 0) $catalogIblockIds[] = $legacyCatalogIblockId;
$catalogIblockIds = array_values(array_unique(array_filter($catalogIblockIds)));
$catalogSections = [];
$catalogIblockNames = [];
foreach ($catalogIblockIds as $catalogIblockId) {
    $catalogIblock = CIBlock::GetByID($catalogIblockId)->Fetch();
    if (!is_array($catalogIblock)) continue;
    $catalogIblockNames[$catalogIblockId] = (string)$catalogIblock['NAME'];
    $sectionResult = CIBlockSection::GetList(['LEFT_MARGIN'=>'ASC'], ['IBLOCK_ID'=>$catalogIblockId], false, ['ID','NAME','DEPTH_LEVEL']);
    while ($catalogSection = $sectionResult->Fetch()) {
        $catalogSections[$catalogIblockId][(int)$catalogSection['ID']] = str_repeat('· ', max(0, (int)$catalogSection['DEPTH_LEVEL'] - 1)) . (string)$catalogSection['NAME'] . ' [ID '.(int)$catalogSection['ID'].']';
    }
}
$currentCatalogSectionId = (int)$getPropertyScalarValue($properties, 'KK_RESULT_CATALOG_SECTION');
$currentProductIds = array_values(array_unique(array_map('intval', $getPropertyMultipleValues($properties, 'KK_RESULT_CATALOG_PRODUCTS'))));
$catalogProducts = [];
$catalogValuesInvalid = false;
if ($type === 'RESULT' && $currentProductIds !== []) {
    $productResult = CIBlockElement::GetList(['ID'=>'ASC'], ['ID'=>$currentProductIds], false, false, ['ID','IBLOCK_ID','NAME','ACTIVE']);
    while ($product = $productResult->Fetch()) {
        if (!in_array((int)$product['IBLOCK_ID'], $catalogIblockIds, true)) { $catalogValuesInvalid = true; continue; }
        $catalogProducts[(int)$product['ID']] = $product;
    }
    if (count($catalogProducts) !== count($currentProductIds)) $catalogValuesInvalid = true;
}
if ($type === 'RESULT' && $currentCatalogSectionId > 0) {
    $catalogSectionValid = false;
    foreach ($catalogSections as $sectionOptions) if (isset($sectionOptions[$currentCatalogSectionId])) $catalogSectionValid = true;
    if (!$catalogSectionValid) $catalogValuesInvalid = true;
}
$catalogUnavailable = $type === 'RESULT' && $catalogIblockIds === [];
$catalogEditorDisabled = $catalogUnavailable || $catalogValuesInvalid;
$questionSaveDisabled = $answersDecodeInvalid || $relationOptionsInvalid || $brokenAnswerTargets || $brokenDefaultTargets;
$saveDisabled = $enumLoadFailed || ($type === 'QUESTION' && $questionSaveDisabled);
$isSave = $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && (isset($_POST['save']) || isset($_POST['apply']));
if ($isSave && $valid) {
    if (($_POST['kk_quiz_custom_editor_loaded'] ?? '') !== 'Y') {
        $errors[] = 'Форма загружена некорректно. Сохранение отменено, чтобы не затереть данные.';
    }
    if ($saveDisabled) {
        $errors[] = 'Часть данных формы не загрузилась. Сохранение отключено, чтобы не затереть данные.';
    }
    $name = trim((string)($_POST['NAME'] ?? ''));
    $code = trim((string)($_POST['CODE'] ?? ''));
    $sort = (string)($_POST['SORT'] ?? '');
    if ($name === '') $errors[] = 'Название обязательно.';
    if ($code === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $code) !== 1) $errors[] = 'Код обязателен и может содержать только латиницу, цифры, дефис и подчёркивание.';
    if (preg_match('/^\d+$/', $sort) !== 1) $errors[] = 'Сортировка должна быть числом не меньше нуля.';
    foreach (['KK_RESULT_CTA_LINK', 'KK_RESULT_SECONDARY_CTA_LINK', 'KK_RESULT_VIDEO_URL'] as $urlField) {
        if (array_key_exists($urlField, $_POST) && !$urlValid(trim((string)$_POST[$urlField]))) {
            $errors[] = 'Некорректная ссылка в поле ' . $urlField . '.';
        }
    }
    $props = [];
    if (array_key_exists('KK_PUBLIC_TITLE', $_POST)) {
        $props['KK_PUBLIC_TITLE'] = trim((string)$_POST['KK_PUBLIC_TITLE']);
    }
    foreach ($type === 'QUESTION' ? $questionCodes : array_diff($resultCodes, ['KK_RESULT_CATALOG_SECTION', 'KK_RESULT_CATALOG_PRODUCTS']) as $codeName) {
        if (!array_key_exists($codeName, $_POST)) {
            continue;
        }
        $normalized = $normalizePostedPropertyValue($codeName, $_POST[$codeName]);
        if ($normalized === null && ($properties[$codeName]['PROPERTY_TYPE'] ?? '') === 'L') {
            $errors[] = 'Недопустимое значение свойства ' . $codeName . '.';
            continue;
        }
        $props[$codeName] = $normalized;
    }
    if ($type === 'QUESTION' && array_key_exists('answers', $_POST) && !$answersDecodeInvalid) {
        $rows = [];
        if ((array)$_POST['answers'] === []) $errors[] = 'Нельзя сохранить вопрос без ответов через кастомную форму.';
        foreach ((array)$_POST['answers'] as $key => $row) {
            if (!is_array($row)) continue;
            $imageId = (int)($row['image_id'] ?? 0);
            if (!empty($row['delete_image'])) $imageId = 0;
            $file = $_FILES['answer_images'] ?? [];
            if (($file['error'][$key] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $upload = ['name'=>$file['name'][$key], 'type'=>$file['type'][$key], 'tmp_name'=>$file['tmp_name'][$key], 'error'=>$file['error'][$key], 'size'=>$file['size'][$key]];
                $extension = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
                $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $imageInfo = is_uploaded_file((string)$upload['tmp_name']) ? @getimagesize((string)$upload['tmp_name']) : false;
                $mimeType = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
                $checkImageError = ($mimeType !== 'image/webp' && class_exists('CFile') && method_exists('CFile', 'CheckImageFile')) ? (string)CFile::CheckImageFile($upload, 10 * 1024 * 1024, 0, 0) : '';
                if ($upload['size'] > 10 * 1024 * 1024 || !in_array($extension, ['jpg','jpeg','png','webp','gif'], true) || $imageInfo === false || !in_array($mimeType, $allowedMimeTypes, true) || $checkImageError !== '') {
                    $errors[] = 'Картинка ответа должна быть настоящим JPG, PNG, WEBP или GIF размером до 10 МБ.';
                } else {
                    $upload['type'] = $mimeType;
                    $savedImageId = (int)CFile::SaveFile($upload, 'kk.quiz/answers');
                    if ($savedImageId > 0) $imageId = $savedImageId; else $errors[] = 'Не удалось сохранить картинку ответа.';
                }
            }
            $rows[] = ['active'=>isset($row['active']) ? 'Y' : 'N', 'sort'=>max(0,(int)($row['sort']??0)), 'text'=>trim((string)($row['text']??'')), 'code'=>trim((string)($row['code']??'')), 'description'=>trim((string)($row['description']??'')), 'image_id'=>$imageId?:null, 'image_src'=>$imageId?(string)CFile::GetPath($imageId):'', 'next_question_id'=>max(0,(int)($row['next_question_id']??0)), 'result_id'=>max(0,(int)($row['result_id']??0)), 'score_result_id'=>max(0,(int)($row['score_result_id']??0)), 'score_value'=>(int)($row['score_value']??0)];
        }
        $props['KK_ANSWERS'] = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($type === 'RESULT' && array_key_exists('catalog_products_loaded', $_POST) && !$catalogEditorDisabled) {
        $updatedProductIds = $currentProductIds;
        $removeIds = array_map('intval', (array)($_POST['remove_catalog_products'] ?? []));
        $updatedProductIds = array_values(array_diff($updatedProductIds, $removeIds));
        $addRaw = trim((string)($_POST['add_catalog_product_ids'] ?? ''));
        $addIds = $addRaw === '' ? [] : array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', $addRaw, -1, PREG_SPLIT_NO_EMPTY)))));
        if ($addIds !== []) {
            $validAddIds = [];
            $checkResult = CIBlockElement::GetList([], ['ID'=>$addIds, 'IBLOCK_ID'=>$catalogIblockIds], false, false, ['ID']);
            while ($checkProduct = $checkResult->Fetch()) $validAddIds[] = (int)$checkProduct['ID'];
            if (count($validAddIds) !== count($addIds)) $errors[] = 'Один или несколько товаров не найдены в подключённых каталогах.';
            else $updatedProductIds = array_values(array_unique(array_merge($updatedProductIds, $validAddIds)));
        }
        $props['KK_RESULT_CATALOG_PRODUCTS'] = $updatedProductIds;
        if (array_key_exists('KK_RESULT_CATALOG_SECTION', $_POST)) $props['KK_RESULT_CATALOG_SECTION'] = (int)$_POST['KK_RESULT_CATALOG_SECTION'] ?: false;
    }
    if ($errors === []) {
        $updater = new CIBlockElement();
        if ($updater->Update($id, ['NAME'=>$name, 'CODE'=>$code, 'SORT'=>(int)$sort, 'ACTIVE'=>isset($_POST['ACTIVE'])?'Y':'N', 'PREVIEW_TEXT'=>(string)($_POST['PREVIEW_TEXT']??$fields['PREVIEW_TEXT']??''), 'DETAIL_TEXT'=>(string)($_POST['DETAIL_TEXT']??$fields['DETAIL_TEXT']??'')])) {
            if ($props !== []) CIBlockElement::SetPropertyValuesEx($id, $iblockId, $props);
            $target = isset($_POST['apply']) ? 'kk_quiz_element_edit.php?' . http_build_query(['ID'=>$id,'SECTION_ID'=>$sectionId,'back'=>$back,'saved'=>'Y','lang'=>$lang]) : $returnUrl . '&saved=Y';
            LocalRedirect($target);
        } else {
            $errors[] = $updater->LAST_ERROR ?: 'Не удалось сохранить элемент.';
        }
    }
    $fields = array_merge($fields, $_POST);
    foreach ($props as $propertyCode => $propertyValue) $properties[$propertyCode]['VALUE'] = $propertyValue;
    $answerRows = $rows ?? $answerRows;
}
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
if (!$valid) { CAdminMessage::ShowMessage('Элемент не найден в указанном квизе или имеет некорректный тип.'); echo '<a class="adm-btn" href="'.htmlspecialcharsbx($returnUrl).'">Вернуться</a>'; require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php'; return; }
if (($_GET['saved'] ?? '') === 'Y') CAdminMessage::ShowMessage(['MESSAGE'=>'Изменения сохранены.','TYPE'=>'OK']);
if ($enumLoadFailed) CAdminMessage::ShowMessage('Не удалось загрузить варианты свойств инфоблока. Сохранение отключено, чтобы не затереть данные.');
if ($answersDecodeInvalid) CAdminMessage::ShowMessage('Не удалось прочитать ответы вопроса. Сохранение отключено, чтобы не затереть данные.');
if ($catalogUnavailable) CAdminMessage::ShowMessage('Каталог не подключён в настройках квиза.');
if ($catalogValuesInvalid) CAdminMessage::ShowMessage('Не удалось корректно загрузить каталоговые рекомендации. Каталоговые поля отключены и не будут изменены.');
if ($relationOptionsInvalid) CAdminMessage::ShowMessage('Не удалось загрузить варианты переходов. Не удалось загрузить список вопросов/результатов квиза. Сохранение вопроса отключено, чтобы не затереть переходы.');
if ($brokenAnswerTargets || $brokenDefaultTargets) CAdminMessage::ShowMessage('Обнаружены ссылки на отсутствующие цели переходов. Сохранение вопроса отключено.');
foreach ($errors as $error) CAdminMessage::ShowMessage($error);
$technical = 'iblock_element_edit.php?' . http_build_query(['IBLOCK_ID'=>$iblockId,'type'=>Installer::IBLOCK_TYPE_ID,'ID'=>$id,'SECTION_ID'=>$sectionId,'find_section_section'=>$sectionId,'lang'=>$lang]);
$esc = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
$select = static function (string $name, mixed $value, array $options, bool $empty = true) use ($esc): string {
    $html = '<select name="'.$esc($name).'">'.($empty?'<option value="">—</option>':'');
    foreach ($options as $key => $label) $html .= '<option value="'.$esc($key).'"'.((string)$key === (string)$value?' selected':'').'>'.$esc($label).'</option>';
    return $html.'</select>';
};
$enumSelectOptions = static function (array $options): array {
    $result = [];
    foreach ($options as $xmlId => $option) $result[$xmlId] = $option['value'];
    return $result;
};
$val = static function (string $key) use ($fields, $properties, $getPropertyScalarValue, $getPropertyMultipleValues): mixed {
    if (array_key_exists($key, $fields)) return $fields[$key];
    if (($properties[$key]['MULTIPLE'] ?? 'N') === 'Y') return $getPropertyMultipleValues($properties, $key);
    return $getPropertyScalarValue($properties, $key);
};
$tabs = $type === 'QUESTION'
    ? [['DIV'=>'main','TAB'=>'Основное'],['DIV'=>'answers','TAB'=>'Ответы'],['DIV'=>'transitions','TAB'=>'Переходы'],['DIV'=>'display','TAB'=>'Отображение'],['DIV'=>'tech','TAB'=>'Техническое']]
    : [['DIV'=>'main','TAB'=>'Основное'],['DIV'=>'content','TAB'=>'Контент результата'],['DIV'=>'cta','TAB'=>'CTA и форма'],['DIV'=>'video','TAB'=>'Видео и каталог'],['DIV'=>'score','TAB'=>'Scoring'],['DIV'=>'tech','TAB'=>'Техническое']];
$tab = new CAdminTabControl('kkQuizElementTabs', $tabs);
$linkHint = 'Разрешены относительные ссылки /catalog/... или http/https.';
$renderAnswerCard = static function (array $answer, string|int $index, int $number) use ($esc, $select, $items, $id): void {
    $nextId = (int)($answer['next_question_id'] ?? 0);
    $resultId = (int)($answer['result_id'] ?? 0);
    $scoreResultId = (int)($answer['score_result_id'] ?? 0);
    $scoreValue = (int)($answer['score_value'] ?? 0);
    ?>
    <div class="kk-quiz-editor-answer">
        <div class="kk-quiz-editor-answer__header">
            <strong class="kk-answer-number">Ответ #<?= $number ?></strong>
            <?php if (trim((string)($answer['code'] ?? '')) !== ''): ?><span class="kk-quiz-editor__badge"><?= $esc($answer['code']) ?></span><?php endif; ?>
            <label><input type="checkbox" name="answers[<?= $esc($index) ?>][active]" <?= !empty($answer['active']) ? 'checked' : '' ?>> Активен</label>
            <label>Сортировка <input class="kk-quiz-editor__sort" type="number" min="0" name="answers[<?= $esc($index) ?>][sort]" value="<?= $esc($answer['sort'] ?? 500) ?>"></label>
            <button type="button" class="adm-btn kk-answer-delete">Удалить</button>
        </div>
        <div class="kk-quiz-editor-answer__body">
            <section class="kk-quiz-editor-answer__main">
                <h4>Основное</h4>
                <label>Текст ответа<input name="answers[<?= $esc($index) ?>][text]" value="<?= $esc($answer['text'] ?? '') ?>"></label>
                <label>Код ответа<input name="answers[<?= $esc($index) ?>][code]" value="<?= $esc($answer['code'] ?? '') ?>"></label>
                <label>Описание ответа<textarea rows="5" name="answers[<?= $esc($index) ?>][description]"><?= $esc($answer['description'] ?? '') ?></textarea></label>
            </section>
            <section class="kk-quiz-editor-answer__image">
                <h4>Изображение</h4>
                <?php if ((int)($answer['image_id'] ?? 0) > 0): ?>
                    <img class="kk-quiz-editor-preview" src="<?= $esc(CFile::GetPath((int)$answer['image_id'])) ?>" alt="">
                    <div class="kk-quiz-editor-muted">image_id: <?= (int)$answer['image_id'] ?></div>
                <?php else: ?><div class="kk-quiz-editor-muted">Изображение не выбрано</div><?php endif; ?>
                <input type="hidden" name="answers[<?= $esc($index) ?>][image_id]" value="<?= (int)($answer['image_id'] ?? 0) ?>">
                <label>Загрузить новое<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" name="answer_images[<?= $esc($index) ?>]"></label>
                <label><input type="checkbox" name="answers[<?= $esc($index) ?>][delete_image]"> Удалить изображение</label>
            </section>
            <section class="kk-quiz-editor-answer__logic">
                <h4>Логика</h4>
                <label>Перейти к вопросу<?= $select("answers[$index][next_question_id]", $nextId, $items['questions']) ?></label>
                <label>Сразу показать результат<?= $select("answers[$index][result_id]", $resultId, $items['results']) ?></label>
                <label>Начислить баллы результату<?= $select("answers[$index][score_result_id]", $scoreResultId, $items['results']) ?></label>
                <label>Баллы<input type="number" name="answers[<?= $esc($index) ?>][score_value]" value="<?= $scoreValue ?>"></label>
                <?php if ($nextId > 0 && $resultId > 0): ?><div class="kk-quiz-editor-warning">У ответа выбраны и вопрос, и результат. Проверьте логику перехода.</div><?php endif; ?>
                <?php if ($scoreResultId > 0 && $scoreValue === 0): ?><div class="kk-quiz-editor-muted">Scoring-результат выбран, но балл 0.</div><?php endif; ?>
                <?php if ($nextId === $id): ?><div class="kk-quiz-editor-warning">Возможен цикл.</div><?php endif; ?>
                <?php if (($answer['target_warnings'] ?? []) !== []): ?><div class="kk-quiz-editor-warning">Цель не найдена: <?= $esc(implode('; ', $answer['target_warnings'])) ?></div><?php endif; ?>
            </section>
        </div>
    </div>
    <?php
};
?>
<style>
.kk-quiz-editor{max-width:1400px}.kk-quiz-editor__section,.kk-quiz-editor__card,.kk-quiz-editor-answer{box-sizing:border-box;background:#fff;border:1px solid #d5dce0;border-radius:6px;margin:0 0 16px;padding:18px}.kk-quiz-editor__section-title{font-size:17px;margin:0 0 14px}.kk-quiz-editor__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.kk-quiz-editor label{display:block;font-weight:600;margin:0 0 12px}.kk-quiz-editor input[type=text],.kk-quiz-editor input:not([type]),.kk-quiz-editor input[type=url],.kk-quiz-editor textarea,.kk-quiz-editor select{box-sizing:border-box;display:block;margin-top:5px;width:100%;max-width:900px}.kk-quiz-editor textarea{min-height:80px;resize:vertical}.kk-quiz-editor select{min-width:280px}.kk-quiz-editor-answer{padding:0;overflow:hidden}.kk-quiz-editor-answer__header{align-items:center;background:#eef2f4;display:flex;flex-wrap:wrap;gap:14px;padding:12px 16px}.kk-quiz-editor-answer__header label{font-weight:normal;margin:0}.kk-answer-delete{margin-left:auto}.kk-quiz-editor-answer__body{display:grid;grid-template-columns:minmax(260px,1.2fr) minmax(190px,.7fr) minmax(300px,1.1fr);gap:20px;padding:18px}.kk-quiz-editor-answer h4{margin:0 0 14px}.kk-quiz-editor-answer__image input[type=file]{display:block;margin:10px 0;max-width:100%}.kk-quiz-editor-preview{background:#f5f5f5;border:1px solid #d5dce0;border-radius:4px;display:block;max-height:160px;max-width:160px;object-fit:contain}.kk-quiz-editor__badge{background:#dce7ef;border-radius:10px;font-family:monospace;padding:3px 8px}.kk-quiz-editor__sort{display:inline-block!important;margin:0 0 0 5px!important;width:75px!important}.kk-quiz-editor-warning{background:#fff4ce;border-left:3px solid #e8a800;margin-top:10px;padding:9px}.kk-quiz-editor-muted{color:#68757d;font-size:12px;margin:7px 0}.kk-quiz-editor__actions{margin:14px 0}.kk-quiz-editor__link-open{margin-left:10px}.kk-quiz-editor table{width:100%}@media(max-width:1000px){.kk-quiz-editor-answer__body,.kk-quiz-editor__grid{grid-template-columns:1fr}.kk-answer-delete{margin-left:0}.kk-quiz-editor select{min-width:0}}
</style>
<form class="kk-quiz-editor" method="post" enctype="multipart/form-data"><?php echo bitrix_sessid_post(); ?><input type="hidden" name="kk_quiz_custom_editor_loaded" value="Y"><?php $tab->Begin(); $tab->BeginNextTab(); ?>
<tr><td width="35%">Активность</td><td><input type="checkbox" name="ACTIVE" value="Y" <?=($val('ACTIVE')==='Y'||isset($_POST['ACTIVE']))?'checked':''?>></td></tr>
<tr class="adm-detail-required-field"><td>Административное название</td><td><input size="70" name="NAME" value="<?=$esc($val('NAME'))?>"></td></tr>
<tr><td>Публичный заголовок</td><td><input size="70" name="KK_PUBLIC_TITLE" value="<?=$esc($val('KK_PUBLIC_TITLE'))?>"></td></tr>
<tr><td>Краткий текст</td><td><textarea name="PREVIEW_TEXT" rows="4"><?=$esc($val('PREVIEW_TEXT'))?></textarea></td></tr>
<tr><td>Детальный текст</td><td><textarea name="DETAIL_TEXT" rows="7"><?=$esc($val('DETAIL_TEXT'))?></textarea></td></tr>
<?php if ($type === 'QUESTION'): $tab->BeginNextTab(); ?>
<tr><td colspan="2"><div id="answers"><?php foreach ($answerRows as $index => $answer) $renderAnswerCard($answer, $index, $index + 1); ?></div><div class="kk-quiz-editor__actions"><button type="button" class="adm-btn" id="add-answer">Добавить ответ</button></div><template id="answer-card-template"><?php $renderAnswerCard(['active'=>true,'sort'=>500,'text'=>'','code'=>'','description'=>'','image_id'=>0,'next_question_id'=>0,'result_id'=>0,'score_result_id'=>0,'score_value'=>0], '__INDEX__', 0); ?></template></td></tr>
<?php $tab->BeginNextTab(); ?>
<tr><td colspan="2"><div class="kk-quiz-editor-muted">Переход по умолчанию используется, если у ответа нет собственного перехода.</div><?php if ($defaultNextQuestionId > 0 && $defaultResultId > 0): ?><div class="kk-quiz-editor-warning">Выбраны и вопрос, и результат по умолчанию. Проверьте логику перехода.</div><?php endif; ?></td></tr>
<tr><td>Следующий вопрос по умолчанию</td><td><?=$select('KK_DEFAULT_NEXT_QUESTION',$defaultNextQuestionId,$items['questions'])?></td></tr>
<tr><td>Результат по умолчанию</td><td><?=$select('KK_DEFAULT_RESULT',$defaultResultId,$items['results'])?></td></tr>
<?php $tab->BeginNextTab(); foreach(['KK_QUESTION_TYPE'=>'Тип вопроса','KK_DISPLAY_TEMPLATE'=>'Шаблон отображения','KK_IMAGE_RATIO'=>'Соотношение картинки','KK_IMAGE_FIT'=>'Режим картинки','KK_IS_REQUIRED'=>'Обязательный вопрос','KK_ALLOW_CUSTOM_ANSWER'=>'Свой вариант'] as $key=>$label): ?><tr><td><?=$label?></td><td><?=$select($key,$getCurrentEnumXmlId($key),$enumSelectOptions($enumOptions[$key]??[]),false)?></td></tr><?php endforeach; ?><tr><td>Placeholder</td><td><input name="KK_PLACEHOLDER" value="<?=$esc($val('KK_PLACEHOLDER'))?>"></td></tr>
<?php else: $tab->BeginNextTab(); ?>
<tr><td colspan="2">
<div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Бейдж и краткое описание</h3><label>Бейдж результата<input name="KK_RESULT_BADGE" value="<?=$esc($val('KK_RESULT_BADGE'))?>"></label><label>Краткое описание<textarea rows="3" name="KK_RESULT_SUMMARY"><?=$esc($val('KK_RESULT_SUMMARY'))?></textarea></label></div>
<div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Обоснование</h3><div class="kk-quiz-editor__grid"><label>Почему мы рекомендуем этот вариант<textarea rows="5" name="KK_RESULT_WHY_TEXT"><?=$esc($val('KK_RESULT_WHY_TEXT'))?></textarea></label><label>Кому подойдёт<textarea rows="5" name="KK_RESULT_FIT_TEXT"><?=$esc($val('KK_RESULT_FIT_TEXT'))?></textarea></label></div></div>
<div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Комплектация</h3><label>Что будет внутри<textarea rows="8" name="KK_RESULT_SPECS_TEXT"><?=$esc($val('KK_RESULT_SPECS_TEXT'))?></textarea></label><div class="kk-quiz-editor-muted">Видеокарта: уровень ...<br>Процессор: ...<br>Память: ...<br>SSD: ...</div></div>
<div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Бюджет и важные замечания</h3><label>Ориентир по бюджету<textarea rows="3" name="KK_RESULT_BUDGET_TEXT"><?=$esc($val('KK_RESULT_BUDGET_TEXT'))?></textarea></label><label>Что важно учесть<textarea rows="4" name="KK_RESULT_NOTE_TEXT"><?=$esc($val('KK_RESULT_NOTE_TEXT'))?></textarea></label></div>
</td></tr>
<?php $tab->BeginNextTab(); ?>
<tr><td colspan="2"><div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Основная кнопка</h3><label>Текст<input name="KK_RESULT_CTA_TEXT" value="<?=$esc($val('KK_RESULT_CTA_TEXT'))?>"></label><label>Ссылка<input name="KK_RESULT_CTA_LINK" value="<?=$esc($val('KK_RESULT_CTA_LINK'))?>"></label><div class="kk-quiz-editor-muted"><?=$linkHint?><?php if($urlValid(trim((string)$val('KK_RESULT_CTA_LINK')))):?> <a class="kk-quiz-editor__link-open" target="_blank" rel="noopener" href="<?=$esc($val('KK_RESULT_CTA_LINK'))?>">Открыть</a><?php endif?></div><label>Target<?=$select('KK_RESULT_CTA_TARGET',$getCurrentEnumXmlId('KK_RESULT_CTA_TARGET'),$enumSelectOptions($enumOptions['KK_RESULT_CTA_TARGET']??[]),false)?></label></div>
<div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Вторая кнопка</h3><label>Текст<input name="KK_RESULT_SECONDARY_CTA_TEXT" value="<?=$esc($val('KK_RESULT_SECONDARY_CTA_TEXT'))?>"></label><label>Ссылка<input name="KK_RESULT_SECONDARY_CTA_LINK" value="<?=$esc($val('KK_RESULT_SECONDARY_CTA_LINK'))?>"></label><div class="kk-quiz-editor-muted"><?=$linkHint?><?php if($urlValid(trim((string)$val('KK_RESULT_SECONDARY_CTA_LINK')))):?> <a class="kk-quiz-editor__link-open" target="_blank" rel="noopener" href="<?=$esc($val('KK_RESULT_SECONDARY_CTA_LINK'))?>">Открыть</a><?php endif?></div><label>Target<?=$select('KK_RESULT_SECONDARY_CTA_TARGET',$getCurrentEnumXmlId('KK_RESULT_SECONDARY_CTA_TARGET'),$enumSelectOptions($enumOptions['KK_RESULT_SECONDARY_CTA_TARGET']??[]),false)?></label></div>
<div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Форма</h3><label>Показывать форму<?=$select('KK_RESULT_SHOW_FORM',$getCurrentEnumXmlId('KK_RESULT_SHOW_FORM'),$enumSelectOptions($enumOptions['KK_RESULT_SHOW_FORM']??[]),false)?></label><label>Заголовок формы<input name="KK_RESULT_FORM_TITLE" value="<?=$esc($val('KK_RESULT_FORM_TITLE'))?>"></label><label>Текст перед формой<textarea rows="4" name="KK_RESULT_FORM_INTRO"><?=$esc($val('KK_RESULT_FORM_INTRO'))?></textarea></label><label>Текст кнопки формы<input name="KK_RESULT_FORM_BUTTON_TEXT" value="<?=$esc($val('KK_RESULT_FORM_BUTTON_TEXT'))?>"></label></div></td></tr>
<?php $tab->BeginNextTab(); ?>
<tr><td colspan="2"><div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Видео</h3><label>URL<input name="KK_RESULT_VIDEO_URL" value="<?=$esc($val('KK_RESULT_VIDEO_URL'))?>"></label><label>Заголовок<input name="KK_RESULT_VIDEO_TITLE" value="<?=$esc($val('KK_RESULT_VIDEO_TITLE'))?>"></label><label>Позиция<?=$select('KK_RESULT_VIDEO_POSITION',$getCurrentEnumXmlId('KK_RESULT_VIDEO_POSITION'),$enumSelectOptions($enumOptions['KK_RESULT_VIDEO_POSITION']??[]),false)?></label></div>
<div class="kk-quiz-editor__section"><h3 class="kk-quiz-editor__section-title">Каталог</h3><?php if($catalogEditorDisabled):?><div class="kk-quiz-editor-warning">Каталоговые данные недоступны для безопасного редактирования. Технические значения не будут изменены.</div><?php endif?><label>Раздел рекомендаций<select name="KK_RESULT_CATALOG_SECTION" <?=$catalogEditorDisabled?'disabled':''?>><option value="">— не выбрано —</option><?php foreach($catalogSections as $catalogIblockId=>$sectionOptions):?><optgroup label="<?=$esc($catalogIblockNames[$catalogIblockId]??('Инфоблок '.$catalogIblockId))?>"><?php foreach($sectionOptions as $sectionOptionId=>$sectionLabel):?><option value="<?=$sectionOptionId?>" <?=$sectionOptionId===$currentCatalogSectionId?'selected':''?>><?=$esc($sectionLabel)?></option><?php endforeach?></optgroup><?php endforeach?></select></label><?php if($catalogEditorDisabled&&$currentCatalogSectionId>0):?><p>Текущий ID раздела: <code><?=$currentCatalogSectionId?></code></p><?php endif?><input type="hidden" name="catalog_products_loaded" value="Y"><table class="adm-list-table"><thead><tr><th>ID</th><th>Название</th><th>Активность</th><th>Действия</th></tr></thead><tbody><?php if($catalogProducts===[]):?><tr><td colspan="4">Товары не выбраны.</td></tr><?php endif;foreach($catalogProducts as$productId=>$product):$productEditUrl='iblock_element_edit.php?'.http_build_query(['IBLOCK_ID'=>(int)$product['IBLOCK_ID'],'type'=>'catalog','ID'=>$productId,'lang'=>$lang]);?><tr><td><?=$productId?></td><td><?=$esc($product['NAME'])?></td><td><?=$product['ACTIVE']==='Y'?'Да':'Нет'?></td><td><a href="<?=$esc($productEditUrl)?>">Техническое редактирование</a> <label><input type="checkbox" name="remove_catalog_products[]" value="<?=$productId?>" <?=$catalogEditorDisabled?'disabled':''?>> Удалить</label></td></tr><?php endforeach?></tbody></table><?php if($catalogEditorDisabled&&$currentProductIds!==[]):?><p>Текущие ID рекомендаций: <code><?=$esc(implode(', ',$currentProductIds))?></code></p><?php endif?><label>Добавить товары по ID, через запятую<input name="add_catalog_product_ids" <?=$catalogEditorDisabled?'disabled':''?>></label></div></td></tr>
<?php $tab->BeginNextTab(); foreach(['KK_RESULT_MIN_SCORE','KK_RESULT_MAX_SCORE','KK_RESULT_PRIORITY'] as$key):?><tr><td><?=$esc($properties[$key]['NAME']??$key)?></td><td><input type="number" name="<?=$key?>" value="<?=$esc($val($key))?>"></td></tr><?php endforeach; endif; $tab->BeginNextTab(); ?>
<tr><td>Символьный код</td><td><input name="CODE" value="<?=$esc($val('CODE'))?>"></td></tr><tr><td>Сортировка</td><td><input type="number" min="0" name="SORT" value="<?=$esc($val('SORT'))?>"></td></tr><tr><td>Тип сущности</td><td><strong><?=$esc($type)?></strong></td></tr><tr><td>ID</td><td><?=$id?></td></tr>
<?php $tab->Buttons(); ?><input type="submit" name="save" class="adm-btn-save" value="Сохранить" <?=$saveDisabled?'disabled':''?>> <input type="submit" name="apply" value="Применить" <?=$saveDisabled?'disabled':''?>> <input type="submit" name="cancel" value="Отмена"> <a class="adm-btn" href="<?=$esc($schemaUrl)?>">Вернуться к схеме</a> <a class="adm-btn" href="<?=$esc($technical)?>">Техническое редактирование в Bitrix</a><?php $tab->End(); ?></form>
<script>
(()=>{const container=document.getElementById('answers'),template=document.getElementById('answer-card-template');if(!container||!template)return;const renumber=()=>container.querySelectorAll('.kk-answer-number').forEach((node,index)=>node.textContent=`Ответ #${index+1}`);container.addEventListener('click',event=>{if(event.target.closest('.kk-answer-delete')){event.target.closest('.kk-quiz-editor-answer')?.remove();renumber()}});document.getElementById('add-answer')?.addEventListener('click',()=>{container.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',String(Date.now())));renumber()})})();
</script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
