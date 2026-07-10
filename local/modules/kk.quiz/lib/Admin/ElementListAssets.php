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
        $script = <<<'JS'
(() => {
const settings = __SETTINGS__;
if (document.getElementById('kk-quiz-element-list-help')) return;
const findAnchor = () => {
const table = document.querySelector('.adm-list-table');
if (table) return table.closest('.adm-list-table-wrap') || table.closest('form') || table;
return document.querySelector('form[name="form_"]') || document.querySelector('form');
};
const normalizeGridId = (value) => {
const id = String(value || '').trim();
if (id.endsWith('_search_container')) {
return id.slice(0, -'_search_container'.length);
}
if (id.endsWith('_search')) {
return id.slice(0, -'_search'.length);
}
return id;
};
const getGridId = () => {
const elements = Array.from(document.querySelectorAll('[id^="tbl_iblock_list_"]'));
for (const element of elements) {
const normalized = normalizeGridId(element.id);
if (normalized && normalized !== element.id) {
return normalized;
}
}
for (const element of elements) {
const normalized = normalizeGridId(element.id);
if (normalized) {
return normalized;
}
}
return '';
};
const applyQuickFilter = (enumId) => {
const gridId = getGridId();
if (!gridId) {
console.warn('KK Quiz: grid/filter id not found');
return;
}
const propertyField = 'PROPERTY_' + settings.entityTypePropertyId;
const url = new URL('/bitrix/services/main/ajax.php', window.location.origin);
url.searchParams.set('mode', 'ajax');
url.searchParams.set('c', 'bitrix:main.ui.filter');
url.searchParams.set('action', 'setFilter');
const currentUrl = new URL(window.location.href);
const sectionId = currentUrl.searchParams.get('SECTION_ID') || currentUrl.searchParams.get('find_section_section') || '';
const body = new URLSearchParams();
body.append('params[FILTER_ID]', gridId);
body.append('params[GRID_ID]', gridId);
body.append('params[action]', 'setFilter');
body.append('params[forAll]', 'false');
body.append('params[apply_filter]', 'Y');
body.append('params[clear_filter]', enumId ? 'N' : 'Y');
body.append('params[with_preset]', 'N');
body.append('params[save]', 'Y');
body.append('params[isSetOutside]', 'false');
body.append('data[fields][FIND]', '');
body.append('data[fields][NAME]', '');
if (sectionId) body.append('data[fields][SECTION_ID]', sectionId);
if (enumId) body.append('data[fields][' + propertyField + '][0]', String(enumId));
body.append('data[rows]', 'NAME,SECTION_ID,' + propertyField);
body.append('data[preset_id]', 'tmp_filter');
body.append('data[name]', '');
fetch(url.toString(), {
method: 'POST',
credentials: 'include',
headers: {
'Content-Type': 'application/x-www-form-urlencoded',
'BX-Ajax': 'true',
'X-Bitrix-Csrf-Token': window.BX && BX.bitrix_sessid ? BX.bitrix_sessid() : ''
},
body
})
.then((response) => response.json())
.then((response) => {
if (!response || response.status !== 'success') {
console.warn('KK Quiz: filter apply failed', response);
return;
}
window.location.reload();
})
.catch((error) => { console.warn('KK Quiz: filter apply request failed', error); });
};
const createLink = (text, enumId) => {
const link = document.createElement('a');
link.href = '#';
link.textContent = text;
link.style.marginRight = '10px';
link.addEventListener('click', (event) => {
event.preventDefault();
applyQuickFilter(enumId);
});
return link;
};
const createBlock = () => {
const block = document.createElement('div');
block.id = 'kk-quiz-element-list-help';
block.style.margin = '0 0 12px 0';
block.style.padding = '10px 12px';
block.style.border = '1px solid #d6d6d6';
block.style.borderRadius = '4px';
block.style.background = '#f7f7f7';
block.style.color = '#333';
const title = document.createElement('div');
title.textContent = 'KK Quiz';
title.style.fontWeight = 'bold';
title.style.marginBottom = '6px';
block.appendChild(title);
const filters = document.createElement('div');
filters.style.marginBottom = '6px';
filters.appendChild(document.createTextNode('Быстрые фильтры: '));
filters.appendChild(createLink('Все', 0));
if (settings.questionEnumId) filters.appendChild(createLink('Только вопросы', settings.questionEnumId));
if (settings.resultEnumId) filters.appendChild(createLink('Только результаты', settings.resultEnumId));
block.appendChild(filters);
const legend = document.createElement('div');
legend.textContent = 'Q — вопрос, R — результат. NAME — техническое название для админки. “Заголовок на сайте” — текст, который видит пользователь.';
block.appendChild(legend);
const columns = document.createElement('div');
columns.style.marginTop = '4px';
columns.style.color = '#666';
columns.textContent = 'Рекомендуемые колонки: Активность, Сортировка, Название, Тип сущности, Заголовок на сайте, Тип вопроса.';
block.appendChild(columns);
return block;
};
const refreshElementListHelp = () => {
if (document.getElementById('kk-quiz-element-list-help')) return;
const anchor = findAnchor();
if (!anchor || !anchor.parentNode) return;
anchor.parentNode.insertBefore(createBlock(), anchor);
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refreshElementListHelp); else refreshElementListHelp();
setTimeout(refreshElementListHelp, 100);
setTimeout(refreshElementListHelp, 500);
})();
JS;

        return str_replace('__SETTINGS__', self::json($settings), $script);
    }

    private static function json(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
