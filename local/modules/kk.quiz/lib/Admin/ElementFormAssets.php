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
        'KK_CODE',
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
            . 'document.addEventListener("change", (event) => {'
            . 'const entityRow = getPropertyRow(settings.entityTypePropertyId);'
            . 'const isEntityControl = event.target && (event.target.matches(`[name^="PROPERTY[${settings.entityTypePropertyId}]"]`) || event.target.matches(`[name^="PROP[${settings.entityTypePropertyId}]"]`) || (entityRow && entityRow.contains(event.target)));'
            . 'if (isEntityControl) applyVisibility();'
            . '});'
            . 'if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", applyVisibility); else applyVisibility();'
            . 'setTimeout(applyVisibility, 100);'
            . 'setTimeout(applyVisibility, 500);'
            . '})();';
    }

    private static function json(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
