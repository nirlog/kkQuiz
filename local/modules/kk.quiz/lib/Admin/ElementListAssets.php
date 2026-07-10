<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Kk\Quiz\Iblock\Installer;

final class ElementListAssets
{
    private const LIST_PAGES = [
        'iblock_element_admin.php',
        'iblock_list_admin.php',
    ];

    public static function onProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($scriptName, self::LIST_PAGES, true)) {
            return;
        }

        if (!Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
        if ($iblockId <= 0 || !self::isQuizIblock($iblockId)) {
            return;
        }

        $entityTypeProperty = self::getProperty($iblockId, 'KK_ENTITY_TYPE');
        if ($entityTypeProperty === null) {
            return;
        }

        $settings = [
            'entityTypePropertyId' => (int)$entityTypeProperty['ID'],
            'questionEnumId' => self::getEnumId((int)$entityTypeProperty['ID'], 'QUESTION'),
            'resultEnumId' => self::getEnumId((int)$entityTypeProperty['ID'], 'RESULT'),
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

    private static function getProperty(int $iblockId, string $code): ?array
    {
        $property = \CIBlockProperty::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'CODE' => $code,
            ]
        )->Fetch();

        return is_array($property) ? $property : null;
    }

    private static function getEnumId(int $propertyId, string $xmlId): int
    {
        if ($propertyId <= 0) {
            return 0;
        }

        $enum = \CIBlockPropertyEnum::GetList(
            [],
            [
                'PROPERTY_ID' => $propertyId,
                'XML_ID' => $xmlId,
            ]
        )->Fetch();

        return is_array($enum) ? (int)$enum['ID'] : 0;
    }

    private static function renderScript(array $settings): string
    {
        return '(() => {'
            . 'const settings = ' . self::json($settings) . ';'
            . 'if (document.getElementById("kk-quiz-element-list-help")) return;'
            . 'const findAnchor = () => {'
            . 'const table = document.querySelector(".adm-list-table");'
            . 'if (table) return table.closest(".adm-list-table-wrap") || table.closest("form") || table;'
            . 'return document.querySelector("form[name=\\"form_\\"]") || document.querySelector("form");'
            . '};'
            . 'const buildUrl = (enumId) => {'
            . 'const url = new URL(window.location.href);'
            . 'const propertyParam = `find_el_property_${settings.entityTypePropertyId}`;'
            . 'if (enumId) {'
            . 'url.searchParams.set(propertyParam, String(enumId));'
            . 'url.searchParams.set("set_filter", "Y");'
            . 'url.searchParams.set("apply_filter", "Y");'
            . '} else {'
            . 'url.searchParams.set(propertyParam, "");'
            . 'url.searchParams.set("set_filter", "Y");'
            . 'url.searchParams.set("apply_filter", "Y");'
            . '}'
            . 'return url.toString();'
            . '};'
            . 'const createLink = (text, enumId) => {'
            . 'const link = document.createElement("a");'
            . 'link.href = buildUrl(enumId);'
            . 'link.textContent = text;'
            . 'link.style.marginRight = "10px";'
            . 'return link;'
            . '};'
            . 'const createBlock = () => {'
            . 'const block = document.createElement("div");'
            . 'block.id = "kk-quiz-element-list-help";'
            . 'block.style.margin = "0 0 12px 0";'
            . 'block.style.padding = "10px 12px";'
            . 'block.style.border = "1px solid #d6d6d6";'
            . 'block.style.borderRadius = "4px";'
            . 'block.style.background = "#f7f7f7";'
            . 'block.style.color = "#333";'
            . 'const title = document.createElement("div");'
            . 'title.textContent = "KK Quiz";'
            . 'title.style.fontWeight = "bold";'
            . 'title.style.marginBottom = "6px";'
            . 'block.appendChild(title);'
            . 'const filters = document.createElement("div");'
            . 'filters.style.marginBottom = "6px";'
            . 'filters.appendChild(document.createTextNode("Быстрые фильтры: "));'
            . 'filters.appendChild(createLink("Все", 0));'
            . 'if (settings.questionEnumId) filters.appendChild(createLink("Только вопросы", settings.questionEnumId));'
            . 'if (settings.resultEnumId) filters.appendChild(createLink("Только результаты", settings.resultEnumId));'
            . 'block.appendChild(filters);'
            . 'const legend = document.createElement("div");'
            . 'legend.textContent = "Q — вопрос, R — результат. NAME — техническое название для админки. “Заголовок на сайте” — текст, который видит пользователь.";'
            . 'block.appendChild(legend);'
            . 'const columns = document.createElement("div");'
            . 'columns.style.marginTop = "4px";'
            . 'columns.style.color = "#666";'
            . 'columns.textContent = "Рекомендуемые колонки: Активность, Сортировка, Название, Тип сущности, Заголовок на сайте, Тип вопроса.";'
            . 'block.appendChild(columns);'
            . 'return block;'
            . '};'
            . 'const refreshElementListHelp = () => {'
            . 'if (document.getElementById("kk-quiz-element-list-help")) return;'
            . 'const anchor = findAnchor();'
            . 'if (!anchor || !anchor.parentNode) return;'
            . 'anchor.parentNode.insertBefore(createBlock(), anchor);'
            . '};'
            . 'if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", refreshElementListHelp); else refreshElementListHelp();'
            . 'setTimeout(refreshElementListHelp, 100);'
            . 'setTimeout(refreshElementListHelp, 500);'
            . '})();';
    }

    private static function json(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
