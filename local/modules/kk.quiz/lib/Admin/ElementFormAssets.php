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

        $settings = [
            'entityTypePropertyId' => $propertyIds['KK_ENTITY_TYPE'],
            'entityTypeEnumMap' => self::getEntityTypeEnumMap($propertyIds['KK_ENTITY_TYPE']),
            'common' => self::mapCodesToIds(self::COMMON_CODES, $propertyIds),
            'question' => self::mapCodesToIds(self::QUESTION_CODES, $propertyIds),
            'result' => self::mapCodesToIds(self::RESULT_CODES, $propertyIds),
            'catalogSectionPropertyId' => $propertyIds['KK_RESULT_CATALOG_SECTION'] ?? 0,
            'catalogSectionsByIblock' => self::getCatalogSectionsByIblock($iblockId),
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

    private static function getCatalogSectionsByIblock(int $quizIblockId): array
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
            . 'const enhanceCatalogSectionSelect = () => {'
            . 'const propertyId = Number(settings.catalogSectionPropertyId || 0);'
            . 'if (!propertyId) return;'
            . 'const row = getPropertyRow(propertyId);'
            . 'if (!row || row.dataset.kkCatalogEnhanced === "Y") return;'
            . 'const original = getPropertyControls(propertyId).find((control) => control.tagName === "SELECT" || control.type === "text" || control.type === "hidden");'
            . 'if (!original) return;'
            . 'row.dataset.kkCatalogEnhanced = "Y";'
            . 'const cells = Array.from(row.children).filter((child) => child.tagName === "TD");'
            . 'const labelCell = cells.length > 1 ? cells[0] : null;'
            . 'const valueCell = cells.length > 1 ? cells[cells.length - 1] : (original.closest("td") || row);'
            . 'if (labelCell) labelCell.textContent = "Раздел рекомендаций:";'
            . 'const groups = Array.isArray(settings.catalogSectionsByIblock) ? settings.catalogSectionsByIblock : [];'
            . 'const currentValue = String(original.value || "");'
            . 'const nativeWrapper = document.createElement("div");'
            . 'nativeWrapper.hidden = true;'
            . 'nativeWrapper.dataset.kkCatalogNative = "Y";'
            . 'while (valueCell.firstChild) nativeWrapper.appendChild(valueCell.firstChild);'
            . 'valueCell.appendChild(nativeWrapper);'
            . 'const customWrapper = document.createElement("div");'
            . 'customWrapper.className = "kk-quiz-admin-recommendation-section";'
            . 'const select = document.createElement("select");'
            . 'select.className = "adm-select";'
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
            . 'original.value = select.value;'
            . 'original.dispatchEvent(new Event("change", { bubbles: true }));'
            . '});'
            . 'customWrapper.appendChild(select);'
            . 'if (groups.length === 0) {'
            . 'const hint = document.createElement("div");'
            . 'hint.textContent = "Сначала выберите инфоблоки рекомендаций в настройках квиза.";'
            . 'hint.style.color = "#777";'
            . 'customWrapper.appendChild(hint);'
            . '}'
            . 'valueCell.appendChild(customWrapper);'
            . '};'
            . 'document.addEventListener("change", (event) => {'
            . 'const entityRow = getPropertyRow(settings.entityTypePropertyId);'
            . 'const isEntityControl = event.target && (event.target.matches(`[name^="PROPERTY[${settings.entityTypePropertyId}]"]`) || event.target.matches(`[name^="PROP[${settings.entityTypePropertyId}]"]`) || (entityRow && entityRow.contains(event.target)));'
            . 'if (isEntityControl) { applyVisibility(); enhanceCatalogSectionSelect(); }'
            . '});'
            . 'if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => { applyVisibility(); enhanceCatalogSectionSelect(); }); else { applyVisibility(); enhanceCatalogSectionSelect(); }'
            . 'setTimeout(() => { applyVisibility(); enhanceCatalogSectionSelect(); }, 100);'
            . 'setTimeout(() => { applyVisibility(); enhanceCatalogSectionSelect(); }, 500);'
            . '})();';
    }

    private static function json(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
