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
        'KK_ADMIN_NOTE',
    ];

    private const QUESTION_CODES = [
        'KK_QUESTION_TYPE',
        'KK_ANSWERS',
        'KK_DISPLAY_TEMPLATE',
        'KK_IS_REQUIRED',
        'KK_PLACEHOLDER',
        'KK_DEFAULT_NEXT_QUESTION',
    ];

    private const RESULT_CODES = [
        'KK_RESULT_PRIORITY',
        'KK_RESULT_MIN_SCORE',
        'KK_RESULT_MAX_SCORE',
        'KK_RESULT_BADGE',
        'KK_RESULT_CTA_TEXT',
        'KK_RESULT_CTA_LINK',
        'KK_RESULT_SHOW_FORM',
        'KK_RESULT_CATALOG_SECTION',
        'KK_RESULT_CATALOG_PRODUCTS',
    ];

    public static function onProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
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

        $settings = [
            'entityTypePropertyId' => $propertyIds['KK_ENTITY_TYPE'],
            'entityTypeEnumMap' => self::getEntityTypeEnumMap($propertyIds['KK_ENTITY_TYPE']),
            'common' => self::mapCodesToIds(self::COMMON_CODES, $propertyIds),
            'question' => self::mapCodesToIds(self::QUESTION_CODES, $propertyIds),
            'result' => self::mapCodesToIds(self::RESULT_CODES, $propertyIds),
            'catalogSectionPropertyId' => $propertyIds['KK_RESULT_CATALOG_SECTION'] ?? 0,
            'catalogProductsPropertyId' => $propertyIds['KK_RESULT_CATALOG_PRODUCTS'] ?? 0,
            'catalogProductsByIblock' => self::getCatalogProductsByIblock($iblockId, $propertyIds['KK_RESULT_CATALOG_PRODUCTS'] ?? 0),
            'catalogSectionsByIblock' => self::getCatalogSectionsByIblock($iblockId),
            'recommendationsEnabled' => $recommendationSettings['enabled'],
            'recommendationsSectionId' => $recommendationSettings['section_id'],
        ];

        Asset::getInstance()->addString('<script>' . self::renderScript($settings) . '</script>');
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

    private static function getCatalogProductsByIblock(int $quizIblockId, int $productsPropertyId): array
    {
        $iblockIds = self::getCurrentQuizCatalogIblockIds($quizIblockId);
        if ($iblockIds === []) {
            return [];
        }

        $selectedIds = self::getCurrentSelectedProductIds($quizIblockId, $productsPropertyId);
        $result = [];

        foreach ($iblockIds as $iblockId) {
            $iblock = \CIBlock::GetByID($iblockId)->Fetch();
            if (!is_array($iblock)) {
                continue;
            }

            $items = [];
            $seenIds = [];
            $elements = \CIBlockElement::GetList(
                ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
                [
                    'IBLOCK_ID' => $iblockId,
                    'ACTIVE' => 'Y',
                    'ACTIVE_DATE' => 'Y',
                ],
                false,
                ['nTopCount' => 300],
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'DETAIL_PAGE_URL']
            );

            while ($element = $elements->GetNext()) {
                $id = (int)$element['ID'];
                $seenIds[] = $id;
                $items[] = [
                    'id' => $id,
                    'name' => (string)($element['NAME'] ?? ''),
                    'url' => (string)($element['DETAIL_PAGE_URL'] ?? ''),
                ];
            }

            $missingSelectedIds = array_values(array_diff($selectedIds, $seenIds));
            if ($missingSelectedIds !== []) {
                $selectedElements = \CIBlockElement::GetList(
                    ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
                    ['IBLOCK_ID' => $iblockId, 'ID' => $missingSelectedIds],
                    false,
                    false,
                    ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'DETAIL_PAGE_URL']
                );

                while ($element = $selectedElements->GetNext()) {
                    $id = (int)$element['ID'];
                    if (in_array($id, $seenIds, true)) {
                        continue;
                    }

                    $seenIds[] = $id;
                    $items[] = [
                        'id' => $id,
                        'name' => (string)($element['NAME'] ?? ''),
                        'url' => (string)($element['DETAIL_PAGE_URL'] ?? ''),
                    ];
                }
            }

            $result[] = [
                'id' => $iblockId,
                'name' => (string)($iblock['NAME'] ?? ''),
                'type' => (string)($iblock['IBLOCK_TYPE_ID'] ?? ''),
                'items' => $items,
                'limited' => count($items) >= 300,
            ];
        }

        return $result;
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

    private static function getCurrentSelectedProductIds(int $quizIblockId, int $productsPropertyId): array
    {
        $elementId = (int)($_REQUEST['ID'] ?? 0);
        if ($elementId <= 0 || $productsPropertyId <= 0) {
            return [];
        }

        $ids = [];
        $properties = \CIBlockElement::GetProperty($quizIblockId, $elementId, [], ['ID' => $productsPropertyId]);
        while ($property = $properties->Fetch()) {
            $value = (int)($property['VALUE'] ?? 0);
            if ($value > 0 && !in_array($value, $ids, true)) {
                $ids[] = $value;
            }
        }

        return $ids;
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
            . 'const applyVisibility = () => {'
            . 'const entityType = getSelectedEntityType();'
            . 'setVisible(allIds, false);'
            . 'setVisible(settings.common, true);'
            . 'if (entityType === "QUESTION") setVisible(settings.question, true);'
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
            . 'const enhanceCatalogProductsSelect = () => {'
            . 'const propertyId = Number(settings.catalogProductsPropertyId || 0);'
            . 'if (!propertyId) return;'
            . 'const row = getPropertyRow(propertyId);'
            . 'if (!row || row.dataset.kkProductsEnhanced === "Y") return;'
            . 'const controls = getPropertyControls(propertyId);'
            . 'const namedControls = controls.filter((control) => control.name);'
            . 'if (namedControls.length === 0) return;'
            . 'const selectedIds = [];'
            . 'controls.forEach((control) => {'
            . 'const value = String(control.value || "").trim();'
            . 'if (value !== "" && !selectedIds.includes(value)) selectedIds.push(value);'
            . '});'
            . 'const baseName = String(namedControls[0].name || "");'
            . 'const buildName = (index) => {'
            . 'const propertyPrefix = `PROPERTY[${propertyId}]`;'
            . 'if (baseName.startsWith(propertyPrefix)) {'
            . 'const suffix = baseName.slice(propertyPrefix.length).replace(/^\\[[^\\]]*\\]/, "") || "[VALUE]";'
            . 'return `${propertyPrefix}[${index}]${suffix}`;'
            . '}'
            . 'return `${propertyPrefix}[${index}][VALUE]`;'
            . '};'
            . 'row.dataset.kkProductsEnhanced = "Y";'
            . 'const cells = Array.from(row.children).filter((child) => child.tagName === "TD");'
            . 'const labelCell = cells.length > 1 ? cells[0] : null;'
            . 'const valueCell = cells.length > 1 ? cells[cells.length - 1] : (namedControls[0].closest("td") || row);'
            . 'if (labelCell) labelCell.textContent = "Рекомендуемые элементы:";'
            . 'const nativeWrapper = document.createElement("div");'
            . 'nativeWrapper.hidden = true;'
            . 'nativeWrapper.dataset.kkProductsNative = "Y";'
            . 'while (valueCell.firstChild) nativeWrapper.appendChild(valueCell.firstChild);'
            . 'nativeWrapper.querySelectorAll("select,input,textarea").forEach((control) => { control.disabled = true; });'
            . 'valueCell.appendChild(nativeWrapper);'
            . 'const customWrapper = document.createElement("div");'
            . 'customWrapper.className = "kk-quiz-admin-recommendation-products";'
            . 'const search = document.createElement("input");'
            . 'search.type = "search";'
            . 'search.className = "adm-input";'
            . 'search.placeholder = "Поиск по названию...";'
            . 'search.style.marginBottom = "8px";'
            . 'customWrapper.appendChild(search);'
            . 'const hiddenInputsWrap = document.createElement("div");'
            . 'hiddenInputsWrap.hidden = true;'
            . 'customWrapper.appendChild(hiddenInputsWrap);'
            . 'const selected = new Set(selectedIds);'
            . 'const knownIds = new Set();'
            . 'const list = document.createElement("div");'
            . 'customWrapper.appendChild(list);'
            . 'const createCheckbox = (item, groupName, stale) => {'
            . 'const label = document.createElement("label");'
            . 'label.style.display = "block";'
            . 'label.style.margin = "4px 0";'
            . 'label.dataset.kkProductName = String(item.name || "").toLowerCase();'
            . 'const checkbox = document.createElement("input");'
            . 'checkbox.type = "checkbox";'
            . 'checkbox.value = String(item.id || "");'
            . 'checkbox.checked = selected.has(checkbox.value);'
            . 'checkbox.style.marginRight = "6px";'
            . 'const text = document.createElement("span");'
            . 'text.textContent = stale ? `Текущее значение #${item.id} — элемент не входит в текущий список рекомендаций` : String(item.name || `#${item.id}`);'
            . 'checkbox.addEventListener("change", () => {'
            . 'if (checkbox.checked) selected.add(checkbox.value); else selected.delete(checkbox.value);'
            . 'renderHiddenInputs();'
            . 'updateRecommendationsDisabledHint();'
            . '});'
            . 'label.appendChild(checkbox);'
            . 'label.appendChild(text);'
            . 'return label;'
            . '};'
            . 'const renderHiddenInputs = () => {'
            . 'hiddenInputsWrap.innerHTML = "";'
            . 'const values = Array.from(selected);'
            . 'if (values.length === 0) values.push("");'
            . 'values.forEach((id, index) => {'
            . 'const input = document.createElement("input");'
            . 'input.type = "hidden";'
            . 'input.name = buildName(index);'
            . 'input.value = String(id);'
            . 'hiddenInputsWrap.appendChild(input);'
            . '});'
            . '};'
            . 'const groups = Array.isArray(settings.catalogProductsByIblock) ? settings.catalogProductsByIblock : [];'
            . 'groups.forEach((group) => {'
            . 'const groupBlock = document.createElement("div");'
            . 'groupBlock.style.margin = "8px 0";'
            . 'const title = document.createElement("div");'
            . 'title.style.fontWeight = "bold";'
            . 'title.textContent = `[${group.type || ""}] ${group.name || ""}`;'
            . 'groupBlock.appendChild(title);'
            . '(Array.isArray(group.items) ? group.items : []).forEach((item) => {'
            . 'const id = String(item.id || "");'
            . 'if (id === "") return;'
            . 'knownIds.add(id);'
            . 'groupBlock.appendChild(createCheckbox(item, group.name || "", false));'
            . '});'
            . 'if (group.limited === true) {'
            . 'const limitHint = document.createElement("div");'
            . 'limitHint.textContent = "Показаны первые 300 элементов. Для точного выбора используйте поиск по названию в инфоблоке или выберите раздел рекомендаций.";'
            . 'limitHint.style.color = "#777";'
            . 'groupBlock.appendChild(limitHint);'
            . '}'
            . 'list.appendChild(groupBlock);'
            . '});'
            . 'selectedIds.forEach((id) => {'
            . 'if (knownIds.has(id)) return;'
            . 'list.appendChild(createCheckbox({ id, name: `#${id}` }, "", true));'
            . '});'
            . 'if (groups.length === 0 && selectedIds.length === 0) {'
            . 'const hint = document.createElement("div");'
            . 'hint.textContent = "Сначала выберите инфоблоки рекомендаций в настройках квиза.";'
            . 'hint.style.color = "#777";'
            . 'list.appendChild(hint);'
            . '}'
            . 'search.addEventListener("input", () => {'
            . 'const query = String(search.value || "").trim().toLowerCase();'
            . 'list.querySelectorAll("label[data-kk-product-name]").forEach((label) => {'
            . 'const checkbox = label.querySelector("input[type=checkbox]");'
            . 'const checked = checkbox ? checkbox.checked : false;'
            . 'label.style.display = query === "" || checked || label.dataset.kkProductName.includes(query) ? "block" : "none";'
            . '});'
            . '});'
            . 'renderHiddenInputs();'
            . 'valueCell.appendChild(customWrapper);'
            . '};'
            . 'document.addEventListener("change", (event) => {'
            . 'const entityRow = getPropertyRow(settings.entityTypePropertyId);'
            . 'const isEntityControl = event.target && (event.target.matches(`[name^="PROPERTY[${settings.entityTypePropertyId}]"]`) || event.target.matches(`[name^="PROP[${settings.entityTypePropertyId}]"]`) || (entityRow && entityRow.contains(event.target)));'
            . 'if (isEntityControl) { applyVisibility(); enhanceCatalogSectionSelect(); enhanceCatalogProductsSelect(); }'
            . 'updateRecommendationsDisabledHint();'
            . '});'
            . 'document.addEventListener("input", () => { updateRecommendationsDisabledHint(); });'
            . 'const refreshAdminForm = () => { applyVisibility(); enhanceCatalogSectionSelect(); enhanceCatalogProductsSelect(); updateRecommendationsDisabledHint(); };'
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
