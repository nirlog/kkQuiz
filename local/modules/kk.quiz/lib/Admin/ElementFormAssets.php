<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Kk\Quiz\Iblock\Installer;

final class ElementFormAssets
{
    private const COMMON_CODES = [
        'KK_ENTITY_TYPE',
        'KK_PUBLIC_TITLE',
        'KK_ADMIN_NOTE',
    ];

    private const QUESTION_CODES = [
        'KK_QUESTION_TYPE',
        'KK_ANSWERS',
        'KK_DISPLAY_TEMPLATE',
        'KK_IS_REQUIRED',
        'KK_PLACEHOLDER',
        'KK_DEFAULT_NEXT_QUESTION',
        'KK_DEFAULT_RESULT',
        'KK_ALLOW_CUSTOM_ANSWER',
    ];

    private const RESULT_CODES = [
        'KK_RESULT_PRIORITY',
        'KK_RESULT_MIN_SCORE',
        'KK_RESULT_MAX_SCORE',
        'KK_RESULT_BADGE',
        'KK_RESULT_SUMMARY',
        'KK_RESULT_WHY_TEXT',
        'KK_RESULT_SPECS_TEXT',
        'KK_RESULT_NOTE_TEXT',
        'KK_RESULT_CTA_TEXT',
        'KK_RESULT_CTA_LINK',
        'KK_RESULT_VIDEO_URL',
        'KK_RESULT_VIDEO_TITLE',
        'KK_RESULT_VIDEO_POSITION',
        'KK_RESULT_SHOW_FORM',
        'KK_RESULT_FORM_INTRO',
        'KK_RESULT_FORM_BUTTON_TEXT',
        'KK_RESULT_VIDEO_URL',
        'KK_RESULT_VIDEO_TITLE',
        'KK_RESULT_VIDEO_POSITION',
        'KK_RESULT_CATALOG_SECTION',
        'KK_RESULT_CATALOG_PRODUCTS',
    ];

    public static function onProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        if (!self::isAdminAllowed()) {
            return;
        }

        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (basename($scriptName) !== 'iblock_element_edit.php') {
            return;
        }

        if (!Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
        if ($iblockId <= 0 || !self::isQuizIblock($iblockId)) {
            return;
        }

        $propertyIds = self::getPropertyIds($iblockId);
        if (empty($propertyIds['KK_ENTITY_TYPE'])) {
            return;
        }

        $recommendationSettings = self::getCurrentQuizRecommendationSettings($iblockId);
        $quizSectionSettings = self::getCurrentQuizSectionSettings($iblockId);

        $settings = [
            'entityTypePropertyId' => $propertyIds['KK_ENTITY_TYPE'],
            'entityTypeEnumMap' => self::getEntityTypeEnumMap($propertyIds['KK_ENTITY_TYPE']),
            'common' => self::mapCodesToIds(self::COMMON_CODES, $propertyIds),
            'question' => self::mapCodesToIds(self::QUESTION_CODES, $propertyIds),
            'result' => self::mapCodesToIds(self::RESULT_CODES, $propertyIds),
            'catalogSectionPropertyId' => $propertyIds['KK_RESULT_CATALOG_SECTION'] ?? 0,
            'catalogProductsPropertyId' => $propertyIds['KK_RESULT_CATALOG_PRODUCTS'] ?? 0,
            'questionTypePropertyId' => $propertyIds['KK_QUESTION_TYPE'] ?? 0,
            'answersPropertyId' => $propertyIds['KK_ANSWERS'] ?? 0,
            'displayTemplatePropertyId' => $propertyIds['KK_DISPLAY_TEMPLATE'] ?? 0,
            'isRequiredPropertyId' => $propertyIds['KK_IS_REQUIRED'] ?? 0,
            'placeholderPropertyId' => $propertyIds['KK_PLACEHOLDER'] ?? 0,
            'defaultNextQuestionPropertyId' => $propertyIds['KK_DEFAULT_NEXT_QUESTION'] ?? 0,
            'defaultResultPropertyId' => $propertyIds['KK_DEFAULT_RESULT'] ?? 0,
            'allowCustomAnswerPropertyId' => $propertyIds['KK_ALLOW_CUSTOM_ANSWER'] ?? 0,
            'showFormPropertyId' => $propertyIds['KK_RESULT_SHOW_FORM'] ?? 0,
            'catalogSectionsByIblock' => self::getCatalogSectionsByIblock($iblockId),
            'recommendationsEnabled' => $recommendationSettings['enabled'],
            'recommendationsSectionId' => $recommendationSettings['section_id'],
            'quizSectionSettings' => $quizSectionSettings,
        ];

        Asset::getInstance()->addString('<script>' . self::renderScript($settings) . '</script>');
    }

    private static function isAdminAllowed(): bool
    {
        global $USER;

        return is_object($USER)
            && method_exists($USER, 'IsAuthorized')
            && method_exists($USER, 'IsAdmin')
            && $USER->IsAuthorized()
            && $USER->IsAdmin();
    }

    private static function isQuizIblock(int $iblockId): bool
    {
        $iblock = \CIBlock::GetList(
            [],
            [
                'ID' => $iblockId,
                'TYPE' => Installer::IBLOCK_TYPE_ID,
                'CODE' => Installer::QUIZZES_IBLOCK_CODE,
            ]
        )->Fetch();

        return is_array($iblock);
    }

    private static function getPropertyIds(int $iblockId): array
    {
        $propertyIds = [];
        $properties = \CIBlockProperty::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
            ]
        );

        while ($property = $properties->Fetch()) {
            $code = (string)($property['CODE'] ?? '');
            if ($code !== '') {
                $propertyIds[$code] = (int)$property['ID'];
            }
        }

        return $propertyIds;
    }

    private static function getEntityTypeEnumMap(int $propertyId): array
    {
        $map = [];
        $enums = \CIBlockPropertyEnum::GetList(
            [],
            [
                'PROPERTY_ID' => $propertyId,
            ]
        );

        while ($enum = $enums->Fetch()) {
            $map[(string)$enum['ID']] = (string)($enum['XML_ID'] ?: $enum['VALUE']);
        }

        return $map;
    }

    private static function mapCodesToIds(array $codes, array $propertyIds): array
    {
        $ids = [];
        foreach ($codes as $code) {
            if (!empty($propertyIds[$code])) {
                $ids[] = $propertyIds[$code];
            }
        }

        return $ids;
    }

    private static function getCurrentQuizCatalogIblockIds(int $quizIblockId): array
    {
        $sectionId = self::getCurrentQuizSectionId($quizIblockId);
        if ($sectionId <= 0) {
            return [];
        }

        $section = \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $quizIblockId, 'ID' => $sectionId],
            false,
            ['ID', 'UF_KK_CATALOG_IBLOCK_ID', 'UF_KK_CATALOG_IBLOCK_IDS']
        )->Fetch();
        if (!is_array($section)) {
            return [];
        }

        $iblockIds = self::normalizeCatalogIblockIds($section['UF_KK_CATALOG_IBLOCK_IDS'] ?? []);
        $legacyIblockId = (int)($section['UF_KK_CATALOG_IBLOCK_ID'] ?? 0);
        if ($iblockIds === [] && $legacyIblockId > 0) {
            $iblockIds = [$legacyIblockId];
        }

        return $iblockIds;
    }

    private static function getCatalogSectionsByIblock(int $quizIblockId): array
    {
        $iblockIds = self::getCurrentQuizCatalogIblockIds($quizIblockId);
        if ($iblockIds === []) {
            return [];
        }

        $result = [];
        foreach ($iblockIds as $iblockId) {
            $iblock = \CIBlock::GetByID($iblockId)->Fetch();
            if (!is_array($iblock)) {
                continue;
            }

            $sections = [];
            $rsSections = \CIBlockSection::GetList(
                ['LEFT_MARGIN' => 'ASC'],
                ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
                false,
                ['ID', 'NAME', 'DEPTH_LEVEL']
            );
            while ($catalogSection = $rsSections->Fetch()) {
                $sections[] = [
                    'id' => (int)$catalogSection['ID'],
                    'name' => (string)$catalogSection['NAME'],
                    'depth' => (int)$catalogSection['DEPTH_LEVEL'],
                ];
            }

            $result[] = [
                'id' => $iblockId,
                'name' => (string)($iblock['NAME'] ?? ''),
                'type' => (string)($iblock['IBLOCK_TYPE_ID'] ?? ''),
                'sections' => $sections,
            ];
        }

        return $result;
    }

    private static function getCurrentQuizSectionId(int $quizIblockId): int
    {
        $sectionId = (int)($_REQUEST['IBLOCK_SECTION_ID'] ?? $_REQUEST['find_section_section'] ?? 0);
        if ($sectionId > 0) {
            return $sectionId;
        }

        $elementId = (int)($_REQUEST['ID'] ?? 0);
        if ($elementId <= 0) {
            return 0;
        }

        $element = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $quizIblockId, 'ID' => $elementId],
            false,
            false,
            ['ID', 'IBLOCK_SECTION_ID']
        )->Fetch();

        return is_array($element) ? (int)($element['IBLOCK_SECTION_ID'] ?? 0) : 0;
    }

    private static function getCurrentQuizRecommendationSettings(int $quizIblockId): array
    {
        $sectionId = self::getCurrentQuizSectionId($quizIblockId);
        if ($sectionId <= 0) {
            return [
                'enabled' => false,
                'section_id' => 0,
            ];
        }

        $section = \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $quizIblockId, 'ID' => $sectionId],
            false,
            ['ID', 'UF_KK_USE_CATALOG']
        )->Fetch();

        if (!is_array($section)) {
            return [
                'enabled' => false,
                'section_id' => $sectionId,
            ];
        }

        return [
            'enabled' => self::toBool($section['UF_KK_USE_CATALOG'] ?? null),
            'section_id' => $sectionId,
        ];
    }


    private static function getCurrentQuizSectionSettings(int $quizIblockId): array
    {
        $sectionId = self::getCurrentQuizSectionId($quizIblockId);
        $defaults = [
            'id' => $sectionId,
            'use_catalog' => false,
            'catalog_iblock_ids' => [],
            'form_fields' => [],
            'required_fields' => [],
            'use_metrika' => false,
            'metrika_counter_id' => '',
        ];

        if ($sectionId <= 0) {
            return $defaults;
        }

        $section = \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $quizIblockId, 'ID' => $sectionId],
            false,
            [
                'ID',
                'UF_KK_USE_CATALOG',
                'UF_KK_CATALOG_IBLOCK_ID',
                'UF_KK_CATALOG_IBLOCK_IDS',
                'UF_KK_FORM_FIELDS',
                'UF_KK_REQUIRED_FIELDS',
                'UF_KK_USE_METRIKA',
                'UF_KK_METRIKA_COUNTER_ID',
            ]
        )->Fetch();

        if (!is_array($section)) {
            return $defaults;
        }

        $catalogIblockIds = self::normalizeCatalogIblockIds($section['UF_KK_CATALOG_IBLOCK_IDS'] ?? []);
        $legacyIblockId = (int)($section['UF_KK_CATALOG_IBLOCK_ID'] ?? 0);
        if ($catalogIblockIds === [] && $legacyIblockId > 0) {
            $catalogIblockIds = [$legacyIblockId];
        }

        return [
            'id' => $sectionId,
            'use_catalog' => self::toBool($section['UF_KK_USE_CATALOG'] ?? null),
            'catalog_iblock_ids' => $catalogIblockIds,
            'form_fields' => self::normalizeUserFieldEnumStringList($section['UF_KK_FORM_FIELDS'] ?? []),
            'required_fields' => self::normalizeUserFieldEnumStringList($section['UF_KK_REQUIRED_FIELDS'] ?? []),
            'use_metrika' => self::toBool($section['UF_KK_USE_METRIKA'] ?? null),
            'metrika_counter_id' => trim((string)($section['UF_KK_METRIKA_COUNTER_ID'] ?? '')),
        ];
    }

    private static function normalizeUserFieldEnumStringList(mixed $value): array
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
                    $item = (string)($enum['XML_ID'] ?: $enum['VALUE']);
                }
            }

            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtoupper(trim((string)$value));

        return in_array($value, ['1', 'Y', 'YES', 'TRUE'], true);
    }

    private static function normalizeCatalogIblockIds(mixed $value): array
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
                    $item = (string)($enum['XML_ID'] ?: $enum['VALUE']);
                }
            }

            $id = (int)$item;
            if ($id > 0 && !in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $result;
    }

    private static function renderScript(array $settings): string
    {
        return '(() => {'
            . 'const settings = ' . self::json($settings) . ';'
            . 'const allIds = [...settings.common, ...settings.question, ...settings.result];'
            . 'const addNameHint = () => {'
            . 'const input = document.querySelector("input[name=\"NAME\"]");'
            . 'if (!input || document.getElementById("kk-quiz-name-technical-hint")) return;'
            . 'const hint = document.createElement("div");'
            . 'hint.id = "kk-quiz-name-technical-hint";'
            . 'hint.textContent = "Название элемента используется только для удобства в админке. Заголовок на сайте задаётся в поле “Заголовок на сайте”.";'
            . 'hint.style.marginTop = "6px";'
            . 'hint.style.color = "#666";'
            . 'hint.style.fontSize = "12px";'
            . 'input.insertAdjacentElement("afterend", hint);'
            . '};'
            . 'const getPropertyRow = (propertyId) => {'
            . 'const byId = document.getElementById(`tr_PROPERTY_${propertyId}`);'
            . 'if (byId) return byId;'
            . 'const input = document.querySelector(`[name^="PROPERTY[${propertyId}]"]`) || document.querySelector(`[name^="PROP[${propertyId}]"]`);'
            . 'return input ? input.closest("tr") : null;'
            . '};'
            . 'const getPropertyControls = (propertyId) => {'
            . 'const selectors = [`[name^="PROPERTY[${propertyId}]"]`, `[name^="PROP[${propertyId}]"]`];'
            . 'const controls = [];'
            . 'selectors.forEach((selector) => {'
            . 'document.querySelectorAll(selector).forEach((control) => { if (!controls.includes(control)) controls.push(control); });'
            . '});'
            . 'const row = getPropertyRow(propertyId);'
            . 'if (row) {'
            . 'row.querySelectorAll("select,input[type=\'radio\'],input[type=\'checkbox\'],input[type=\'hidden\'],input[type=\'text\']").forEach((control) => {'
            . 'if (!controls.includes(control)) controls.push(control);'
            . '});'
            . '}'
            . 'return controls;'
            . '};'
            . 'const normalizeType = (value) => {'
            . 'const normalized = String(value || "").trim().toUpperCase();'
            . 'if (normalized.includes("QUESTION") || normalized.includes("ВОПРОС")) return "QUESTION";'
            . 'if (normalized.includes("RESULT") || normalized.includes("РЕЗУЛЬТАТ")) return "RESULT";'
            . 'return normalized;'
            . '};'
            . 'const getSelectedEntityType = () => {'
            . 'const controls = getPropertyControls(settings.entityTypePropertyId);'
            . 'for (const control of controls) {'
            . 'if (control.tagName === "SELECT") {'
            . 'const selected = control.options[control.selectedIndex];'
            . 'const enumType = settings.entityTypeEnumMap[control.value];'
            . 'return normalizeType(enumType || control.value || (selected ? selected.textContent : ""));'
            . '}'
            . 'if ((control.type === "radio" || control.type === "checkbox") && control.checked) {'
            . 'return normalizeType(settings.entityTypeEnumMap[control.value] || control.value);'
            . '}'
            . 'if (control.type !== "radio" && control.type !== "checkbox" && control.value) {'
            . 'return normalizeType(settings.entityTypeEnumMap[control.value] || control.value);'
            . '}'
            . '}'
            . 'return "";'
            . '};'
            . 'const setVisible = (propertyIds, visible) => {'
            . 'propertyIds.forEach((propertyId) => {'
            . 'const row = getPropertyRow(propertyId);'
            . 'if (row) row.style.display = visible ? "" : "none";'
            . '});'
            . '};'
            . 'const normalizeQuestionTypeValue = (value) => {'
            . 'const normalized = String(value || "").trim().toUpperCase();'
            . 'if (normalized.includes("SELECT") || normalized.includes("ВЫПАД")) return "select";'
            . 'if (normalized.includes("CHECK") || normalized.includes("НЕСКОЛЬ")) return "checkbox";'
            . 'if (normalized.includes("TEXTAREA") || normalized.includes("БОЛЬШ")) return "textarea";'
            . 'if (normalized.includes("PHONE") || normalized.includes("ТЕЛ")) return "phone";'
            . 'if (normalized.includes("EMAIL") || normalized.includes("MAIL")) return "email";'
            . 'if (normalized.includes("TEXT") || normalized.includes("ТЕКСТ")) return "text";'
            . 'return "radio";'
            . '};'
            . 'const normalizeDisplayTemplateValue = (value) => {'
            . 'const normalized = String(value || "").trim().toUpperCase();'
            . 'if (normalized.includes("INPUT") || normalized.includes("ПОЛЕ ВВОД")) return "input";'
            . 'if (normalized.includes("SELECT") || normalized.includes("ВЫПАД")) return "select";'
            . 'if (normalized.includes("IMAGE") || normalized.includes("ИЗОБРАЖ")) return "image_cards";'
            . 'if (normalized.includes("CARD") || normalized.includes("КАРТОЧ")) return "cards";'
            . 'return "list";'
            . '};'
            . 'const getSelectedPropertySemanticValue = (propertyId, normalize) => {'
            . 'const controls = getPropertyControls(propertyId);'
            . 'for (const control of controls) {'
            . 'if (control.tagName === "SELECT") {'
            . 'const selected = control.options[control.selectedIndex];'
            . 'return normalize((selected ? selected.textContent : "") || control.value);'
            . '}'
            . 'if ((control.type === "radio" || control.type === "checkbox") && control.checked) return normalize(control.value);'
            . 'if (control.type !== "radio" && control.type !== "checkbox" && control.value) return normalize(control.value);'
            . '}'
            . 'return normalize("");'
            . '};'
            . 'const getSelectedQuestionType = () => getSelectedPropertySemanticValue(settings.questionTypePropertyId, normalizeQuestionTypeValue);'
            . 'const getSelectedDisplayTemplate = () => getSelectedPropertySemanticValue(settings.displayTemplatePropertyId, normalizeDisplayTemplateValue);'
            . 'const setQuestionSubtypeVisibility = () => {'
            . 'const questionType = getSelectedQuestionType();'
            . 'const isChoiceQuestion = ["radio", "checkbox", "select"].includes(questionType);'
            . 'setVisible([settings.answersPropertyId, settings.displayTemplatePropertyId].filter(Boolean), isChoiceQuestion);'
            . 'setVisible([settings.placeholderPropertyId].filter(Boolean), !isChoiceQuestion);'
            . 'setVisible([settings.allowCustomAnswerPropertyId].filter(Boolean), isChoiceQuestion);'
            . 'setVisible([settings.isRequiredPropertyId, settings.defaultNextQuestionPropertyId].filter(Boolean), true);'
            . '};'
            . 'const hideLegacyOption = (propertyId, legacyValue, normalize) => {'
            . 'getPropertyControls(propertyId).forEach((control) => {'
            . 'if (control.tagName !== "SELECT") return;'
            . 'Array.from(control.options).forEach((option) => {'
            . 'const value = normalize(option.textContent || option.value);'
            . 'option.hidden = value === legacyValue && !option.selected;'
            . 'option.disabled = value === legacyValue && !option.selected;'
            . '});'
            . '});'
            . '};'
            . 'const applyQuestionModelAdminRules = () => {'
            . 'hideLegacyOption(settings.questionTypePropertyId, "select", normalizeQuestionTypeValue);'
            . 'hideLegacyOption(settings.displayTemplatePropertyId, "input", normalizeDisplayTemplateValue);'
            . 'if (getSelectedEntityType() === "QUESTION") setQuestionSubtypeVisibility();'
            . '};'
            . 'const applyVisibility = () => {'
            . 'const entityType = getSelectedEntityType();'
            . 'setVisible(allIds, false);'
            . 'setVisible(settings.common, true);'
            . 'if (entityType === "QUESTION") {'
            . 'setVisible(settings.question, true);'
            . 'applyQuestionModelAdminRules();'
            . '}'
            . 'if (entityType === "RESULT") setVisible(settings.result, true);'
            . '};'
            . 'const hasFilledRecommendationFields = () => {'
            . 'const sectionPropertyId = Number(settings.catalogSectionPropertyId || 0);'
            . 'const productsPropertyId = Number(settings.catalogProductsPropertyId || 0);'
            . 'let hasSection = false;'
            . 'let hasProducts = false;'
            . 'if (sectionPropertyId) {'
            . 'getPropertyControls(sectionPropertyId).forEach((control) => {'
            . 'if (!control.disabled && control.type !== "checkbox" && control.type !== "radio" && String(control.value || "").trim() !== "") hasSection = true;'
            . '});'
            . '}'
            . 'if (productsPropertyId) {'
            . 'getPropertyControls(productsPropertyId).forEach((control) => {'
            . 'if (!control.disabled && (control.type !== "checkbox" || control.checked) && (control.type !== "radio" || control.checked) && String(control.value || "").trim() !== "") hasProducts = true;'
            . '});'
            . '}'
            . 'return hasSection || hasProducts;'
            . '};'
            . 'const updateRecommendationsDisabledHint = () => {'
            . 'const sectionPropertyId = Number(settings.catalogSectionPropertyId || 0);'
            . 'const productsPropertyId = Number(settings.catalogProductsPropertyId || 0);'
            . 'const anchorPropertyId = sectionPropertyId || productsPropertyId;'
            . 'if (!anchorPropertyId) return;'
            . 'const row = getPropertyRow(anchorPropertyId);'
            . 'if (!row) return;'
            . 'const existing = document.getElementById("kk-quiz-recommendations-disabled-hint");'
            . 'const entityType = getSelectedEntityType();'
            . 'const shouldShow = entityType === "RESULT" && settings.recommendationsEnabled !== true && hasFilledRecommendationFields();'
            . 'if (!shouldShow) {'
            . 'if (existing) existing.remove();'
            . 'return;'
            . '}'
            . 'if (existing) return;'
            . 'const hint = document.createElement("div");'
            . 'hint.id = "kk-quiz-recommendations-disabled-hint";'
            . 'hint.textContent = "Рекомендации не будут показаны на сайте, потому что в настройках квиза выключено “Показывать рекомендации”.";'
            . 'hint.style.margin = "8px 0";'
            . 'hint.style.padding = "8px 10px";'
            . 'hint.style.border = "1px solid #f0c36d";'
            . 'hint.style.background = "#fff8e5";'
            . 'hint.style.color = "#6b4e00";'
            . 'row.insertAdjacentElement("beforebegin", hint);'
            . '};'
            . 'const getControlValue = (control) => {'
            . 'if (!control || control.disabled) return "";'
            . 'if ((control.type === "checkbox" || control.type === "radio") && !control.checked) return "";'
            . 'return String(control.value || "").trim();'
            . '};'
            . 'const hasPropertyValue = (propertyId) => Number(propertyId || 0) > 0 && getPropertyControls(propertyId).some((control) => getControlValue(control) !== "");'
            . 'const hasControlValueByNames = (names) => names.some((name) => Array.from(document.querySelectorAll(`[name*="${name}"]`)).some((control) => getControlValue(control) !== ""));'
            . 'const isTruthyValue = (value) => ["1", "Y", "YES", "TRUE", "ON", "ДА"].includes(String(value || "").trim().toUpperCase());'
            . 'const isPropertyTruthy = (propertyId) => Number(propertyId || 0) > 0 && getPropertyControls(propertyId).some((control) => isTruthyValue(getControlValue(control)) || (control.tagName === "SELECT" && isTruthyValue(control.options[control.selectedIndex]?.textContent || "")));'
            . 'const hasElementText = () => ["PREVIEW_TEXT", "DETAIL_TEXT"].some((name) => Array.from(document.querySelectorAll(`textarea[name="${name}"], input[name="${name}"]`)).some((control) => getControlValue(control) !== ""));'
            . 'const getDiagnosticsAnchor = () => getPropertyRow(settings.entityTypePropertyId) || document.querySelector("form table.edit-table, form table.adm-detail-content-table, form");'
            . 'const upsertDiagnosticsBlock = (diagnostics) => {'
            . 'const existing = document.getElementById("kk-quiz-admin-diagnostics");'
            . 'const anchor = getDiagnosticsAnchor();'
            . 'if (!anchor) return;'
            . 'const block = existing || document.createElement("div");'
            . 'block.id = "kk-quiz-admin-diagnostics";'
            . 'block.style.margin = "0 0 14px 0";'
            . 'block.style.padding = "10px 12px";'
            . 'block.style.border = "1px solid #d6d6d6";'
            . 'block.style.borderRadius = "4px";'
            . 'block.style.background = "#f6fff2";'
            . 'const hasErrors = diagnostics.some((item) => item.type === "error");'
            . 'const hasWarnings = diagnostics.some((item) => item.type === "warning");'
            . 'if (hasErrors) block.style.background = "#fff1f0"; else if (hasWarnings) block.style.background = "#fff8e5";'
            . 'block.innerHTML = "";'
            . 'const title = document.createElement("div");'
            . 'title.textContent = "Проверка квиза";'
            . 'title.style.fontWeight = "bold";'
            . 'title.style.marginBottom = "6px";'
            . 'block.appendChild(title);'
            . '(diagnostics.length > 0 ? diagnostics : [{ type: "success", message: "Критичных проблем не найдено." }]).forEach((item) => {'
            . 'const line = document.createElement("div");'
            . 'line.style.margin = "4px 0";'
            . 'line.style.color = item.type === "error" ? "#a40000" : (item.type === "warning" ? "#6b4e00" : "#267000");'
            . 'line.textContent = `${item.type === "error" ? "✕" : (item.type === "warning" ? "⚠" : "✓")} ${item.message}`;'
            . 'block.appendChild(line);'
            . '});'
            . 'if (!existing) anchor.insertAdjacentElement("beforebegin", block);'
            . '};'
            . 'const renderQuizDiagnostics = () => {'
            . 'const diagnostics = [];'
            . 'const entityType = getSelectedEntityType();'
            . 'const sectionSettings = settings.quizSectionSettings || {};'
            . 'const catalogIblockIds = Array.isArray(sectionSettings.catalog_iblock_ids) ? sectionSettings.catalog_iblock_ids : [];'
            . 'const formFields = Array.isArray(sectionSettings.form_fields) ? sectionSettings.form_fields.map(String) : [];'
            . 'const requiredFields = Array.isArray(sectionSettings.required_fields) ? sectionSettings.required_fields.map(String) : [];'
            . 'if (entityType === "") diagnostics.push({ type: "error", message: "Ошибка: выберите тип элемента — Вопрос или Результат." });'
            . 'if (entityType === "QUESTION") {'
            . 'const questionType = getSelectedQuestionType();'
            . 'const displayTemplate = getSelectedDisplayTemplate();'
            . 'const isChoiceQuestion = ["radio", "checkbox", "select"].includes(questionType);'
            . 'const hasAnswers = hasPropertyValue(settings.answersPropertyId);'
            . 'if (isChoiceQuestion && !hasAnswers) diagnostics.push({ type: "warning", message: "Предупреждение: у вопроса с вариантами ответа не заполнены ответы." });'
            . 'if (!isChoiceQuestion && hasAnswers) diagnostics.push({ type: "warning", message: "Предупреждение: у текстового вопроса заполнены “Ответы квиза”, но они не используются на сайте." });'
            . 'if (["radio", "checkbox"].includes(questionType) && displayTemplate === "input") diagnostics.push({ type: "warning", message: "Предупреждение: шаблон “Поле ввода” устарел и не применяется. Используйте тип вопроса “Текстовое поле”." });'
            . 'if (questionType === "checkbox" && displayTemplate === "select") diagnostics.push({ type: "warning", message: "Предупреждение: шаблон “Выпадающий список” применим только для одного варианта ответа. Для нескольких вариантов будет использован обычный список." });'
            . 'if (questionType === "select") diagnostics.push({ type: "warning", message: "Предупреждение: тип вопроса “Выпадающий список” устарел. Используйте “Один вариант ответа” + шаблон “Выпадающий список”." });'
            . 'const hasDefaultNextQuestion = hasPropertyValue(settings.defaultNextQuestionPropertyId);'
            . 'const hasDefaultResult = hasPropertyValue(settings.defaultResultPropertyId);'
            . 'const hasCustomAnswer = isPropertyTruthy(settings.allowCustomAnswerPropertyId);'
            . 'if (isChoiceQuestion && hasCustomAnswer && !hasDefaultNextQuestion && !hasDefaultResult) diagnostics.push({ type: "warning", message: "Предупреждение: “Свой вариант ответа” включён, но у вопроса нет “Следующего вопроса по умолчанию” или “Финального результата по умолчанию”. Пользователь с собственным вариантом попадёт на финальную форму без результата." });'
            . 'if (hasDefaultNextQuestion && hasDefaultResult) diagnostics.push({ type: "warning", message: "Предупреждение: одновременно задан “Следующий вопрос по умолчанию” и “Финальный результат по умолчанию”. Сначала будет использован следующий вопрос." });'
            . 'const hasTransitions = hasDefaultNextQuestion || hasDefaultResult || hasControlValueByNames(["default_next_question_id", "default_result_id", "next_question_id", "result_id", "score_result_id"]);'
            . 'if (!hasTransitions) diagnostics.push({ type: "warning", message: "Предупреждение: у вопроса не настроены переходы. Пользователь может не дойти до результата." });'
            . '}'
            . 'if (entityType === "RESULT") {'
            . 'if (!hasElementText()) diagnostics.push({ type: "warning", message: "Предупреждение: у результата не заполнено описание." });'
            . 'if (settings.recommendationsEnabled !== true && hasFilledRecommendationFields()) diagnostics.push({ type: "warning", message: "Предупреждение: рекомендации не будут показаны на сайте, потому что в настройках квиза выключено “Показывать рекомендации”." });'
            . 'if (hasPropertyValue(settings.catalogSectionPropertyId) && catalogIblockIds.length === 0) diagnostics.push({ type: "warning", message: "Предупреждение: выбран раздел рекомендаций, но у квиза не выбраны инфоблоки рекомендаций." });'
            . 'if (isPropertyTruthy(settings.showFormPropertyId) && formFields.length === 0) diagnostics.push({ type: "error", message: "Ошибка: форма результата включена, но в настройках квиза не выбраны поля формы." });'
            . '}'
            . 'if (sectionSettings.use_catalog === true && catalogIblockIds.length === 0) diagnostics.push({ type: "error", message: "Ошибка: рекомендации включены, но не выбраны инфоблоки рекомендаций." });'
            . 'if (requiredFields.some((field) => !formFields.includes(field))) diagnostics.push({ type: "error", message: "Ошибка: обязательные поля формы не входят в список видимых полей." });'
            . 'if (sectionSettings.use_metrika === true && String(sectionSettings.metrika_counter_id || "").trim() === "") diagnostics.push({ type: "warning", message: "Предупреждение: Яндекс.Метрика включена, но не указан ID счётчика." });'
            . 'upsertDiagnosticsBlock(diagnostics);'
            . '};'
            . 'const enhanceCatalogSectionSelect = () => {'
            . 'const propertyId = Number(settings.catalogSectionPropertyId || 0);'
            . 'if (!propertyId) return;'
            . 'const row = getPropertyRow(propertyId);'
            . 'if (!row || row.dataset.kkCatalogEnhanced === "Y") return;'
            . 'const controls = getPropertyControls(propertyId);'
            . 'const saveControl = controls.find((control) => control.tagName === "SELECT" && control.name) || controls.find((control) => control.type === "hidden" && control.name) || controls.find((control) => control.type === "text" && control.name);'
            . 'if (!saveControl) return;'
            . 'row.dataset.kkCatalogEnhanced = "Y";'
            . 'const cells = Array.from(row.children).filter((child) => child.tagName === "TD");'
            . 'const labelCell = cells.length > 1 ? cells[0] : null;'
            . 'const valueCell = cells.length > 1 ? cells[cells.length - 1] : (saveControl.closest("td") || row);'
            . 'if (labelCell) labelCell.textContent = "Раздел рекомендаций:";'
            . 'const groups = Array.isArray(settings.catalogSectionsByIblock) ? settings.catalogSectionsByIblock : [];'
            . 'const currentValue = String(saveControl.value || "");'
            . 'const saveName = String(saveControl.name || "");'
            . 'const nativeWrapper = document.createElement("div");'
            . 'nativeWrapper.hidden = true;'
            . 'nativeWrapper.dataset.kkCatalogNative = "Y";'
            . 'while (valueCell.firstChild) nativeWrapper.appendChild(valueCell.firstChild);'
            . 'valueCell.appendChild(nativeWrapper);'
            . 'const customWrapper = document.createElement("div");'
            . 'customWrapper.className = "kk-quiz-admin-recommendation-section";'
            . 'const select = document.createElement("select");'
            . 'select.className = "adm-select";'
            . 'if (saveName !== "") {'
            . 'select.name = saveName;'
            . 'saveControl.dataset.kkOriginalName = saveName;'
            . 'saveControl.removeAttribute("name");'
            . '}'
            . 'if (!saveControl.id) saveControl.id = `kk_quiz_catalog_section_native_${propertyId}`;'
            . 'select.dataset.kkTargetControlId = saveControl.id;'
            . 'const empty = document.createElement("option");'
            . 'empty.value = "";'
            . 'empty.textContent = "Не выбран";'
            . 'select.appendChild(empty);'
            . 'groups.forEach((group) => {'
            . 'const optgroup = document.createElement("optgroup");'
            . 'optgroup.label = `[${group.type || ""}] ${group.name || ""}`;'
            . '(Array.isArray(group.sections) ? group.sections : []).forEach((section) => {'
            . 'const option = document.createElement("option");'
            . 'const depth = Math.max(1, Number(section.depth || 1));'
            . 'option.value = String(section.id || "");'
            . 'option.textContent = `${"\u00A0\u00A0\u00A0\u00A0".repeat(depth - 1)}${section.name || ""}`;'
            . 'optgroup.appendChild(option);'
            . '});'
            . 'select.appendChild(optgroup);'
            . '});'
            . 'let hasCurrentValue = currentValue === "";'
            . 'Array.from(select.options).forEach((option) => {'
            . 'if (option.value === currentValue) hasCurrentValue = true;'
            . '});'
            . 'if (currentValue !== "" && !hasCurrentValue) {'
            . 'const stale = document.createElement("option");'
            . 'stale.value = currentValue;'
            . 'stale.textContent = `Текущее значение #${currentValue} — не входит в выбранные инфоблоки рекомендаций`;'
            . 'select.appendChild(stale);'
            . '}'
            . 'select.value = currentValue;'
            . 'select.addEventListener("change", () => {'
            . 'saveControl.value = select.value;'
            . 'saveControl.dispatchEvent(new Event("change", { bubbles: true }));'
            . '});'
            . 'customWrapper.appendChild(select);'
            . 'if (groups.length === 0) {'
            . 'const hint = document.createElement("div");'
            . 'hint.textContent = "Сначала выберите инфоблоки рекомендаций в настройках квиза.";'
            . 'hint.style.color = "#777";'
            . 'customWrapper.appendChild(hint);'
            . '}'
            . 'valueCell.appendChild(customWrapper);'
            . 'const form = row.closest("form");'
            . 'if (form && form.dataset.kkCatalogSubmitSync !== "Y") {'
            . 'form.dataset.kkCatalogSubmitSync = "Y";'
            . 'form.addEventListener("submit", () => {'
            . 'document.querySelectorAll(".kk-quiz-admin-recommendation-section select").forEach((customSelect) => {'
            . 'const targetId = customSelect.dataset.kkTargetControlId || "";'
            . 'const target = targetId ? document.getElementById(targetId) : null;'
            . 'if (target) target.value = customSelect.value;'
            . '});'
            . '});'
            . '}'
            . '};'
            . 'document.addEventListener("change", (event) => {'
            . 'const entityRow = getPropertyRow(settings.entityTypePropertyId);'
            . 'const isEntityControl = event.target && (event.target.matches(`[name^="PROPERTY[${settings.entityTypePropertyId}]"]`) || event.target.matches(`[name^="PROP[${settings.entityTypePropertyId}]"]`) || (entityRow && entityRow.contains(event.target)));'
            . 'if (isEntityControl) { applyVisibility(); enhanceCatalogSectionSelect(); } else { applyQuestionModelAdminRules(); }'
            . 'updateRecommendationsDisabledHint();'
            . 'renderQuizDiagnostics();'
            . '});'
            . 'document.addEventListener("input", () => { updateRecommendationsDisabledHint(); renderQuizDiagnostics(); });'
            . 'const refreshAdminForm = () => { addNameHint(); applyVisibility(); enhanceCatalogSectionSelect(); updateRecommendationsDisabledHint(); renderQuizDiagnostics(); };'
            . 'if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", refreshAdminForm); else { refreshAdminForm(); }'
            . 'setTimeout(refreshAdminForm, 100);'
            . 'setTimeout(refreshAdminForm, 500);'
            . '})();';
    }

    private static function json(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
