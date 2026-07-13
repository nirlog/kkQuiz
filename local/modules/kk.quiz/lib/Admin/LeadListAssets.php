<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Kk\Quiz\Iblock\Installer;

final class LeadListAssets
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

        if (!self::isAdminAllowed()) {
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
        if ($iblockId <= 0 || !self::isLeadsIblock($iblockId)) {
            return;
        }

        Asset::getInstance()->addString('<script>' . self::renderScript() . '</script>');
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

    private static function isLeadsIblock(int $iblockId): bool
    {
        $iblock = \CIBlock::GetList(
            [],
            [
                'ID' => $iblockId,
                'TYPE' => Installer::IBLOCK_TYPE_ID,
                'CODE' => Installer::LEADS_IBLOCK_CODE,
            ]
        )->Fetch();

        return is_array($iblock);
    }

    private static function renderScript(): string
    {
        return <<<'JS'
(() => {
if (document.getElementById('kk-quiz-lead-list-actions')) return;
const findAnchor = () => {
const table = document.querySelector('.adm-list-table');
if (table) return table.closest('.adm-list-table-wrap') || table.closest('form') || table;
return document.querySelector('form[name="form_"]') || document.querySelector('form');
};
const getAdminQuizAjaxUrl = (action) => {
const params = new URLSearchParams();
params.set('action', action);
if (window.BX && BX.bitrix_sessid) {
params.set('sessid', BX.bitrix_sessid());
}
return '/bitrix/services/main/ajax.php?' + params.toString();
};
const exportLeadsCsv = (button) => {
const originalText = button.textContent;
button.disabled = true;
button.textContent = 'Экспорт...';
fetch(getAdminQuizAjaxUrl('kk:quiz.api.exportLeads'), {
method: 'POST',
credentials: 'same-origin',
headers: {
'Content-Type': 'application/json'
},
body: JSON.stringify({})
})
.then((response) => response.json())
.then((response) => {
const data = response && response.data ? response.data : response;
if (!data || data.success !== true || !data.content) {
console.warn('KK Quiz: leads export failed', response);
throw new Error('EXPORT_LEADS_FAILED');
}
const filename = data.filename || 'kk-quiz-leads.csv';
const blob = new Blob(
[data.content],
{ type: 'text/csv;charset=utf-8' }
);
const url = URL.createObjectURL(blob);
const link = document.createElement('a');
link.href = url;
link.download = filename;
document.body.appendChild(link);
link.click();
link.remove();
setTimeout(() => URL.revokeObjectURL(url), 1000);
})
.catch((error) => {
console.warn('KK Quiz: leads export failed', { error });
alert('Не удалось экспортировать заявки. Подробности в консоли.');
})
.finally(() => {
button.disabled = false;
button.textContent = originalText;
});
};
const createBlock = () => {
const block = document.createElement('div');
block.id = 'kk-quiz-lead-list-actions';
block.style.margin = '0 0 12px 0';
block.style.padding = '10px 12px';
block.style.border = '1px solid #d6d6d6';
block.style.borderRadius = '4px';
block.style.background = '#f7f7f7';
block.style.color = '#333';
const title = document.createElement('div');
title.textContent = 'KK Quiz — заявки';
title.style.fontWeight = 'bold';
title.style.marginBottom = '6px';
block.appendChild(title);
const button = document.createElement('button');
button.type = 'button';
button.className = 'adm-btn';
button.textContent = 'Экспорт заявок CSV';
button.addEventListener('click', () => exportLeadsCsv(button));
block.appendChild(button);
const statsLink = document.createElement('a');
statsLink.className = 'adm-btn';
statsLink.href = '/bitrix/admin/kk_quiz_statistics.php?lang=' + encodeURIComponent((window.BX && BX.message ? BX.message('LANGUAGE_ID') : '') || 'ru');
statsLink.textContent = 'Статистика';
statsLink.style.marginLeft = '8px';
block.appendChild(statsLink);
return block;
};
const mount = () => {
const anchor = findAnchor();
if (!anchor || !anchor.parentNode || document.getElementById('kk-quiz-lead-list-actions')) return;
anchor.parentNode.insertBefore(createBlock(), anchor);
};
if (document.readyState === 'loading') {
document.addEventListener('DOMContentLoaded', mount);
} else {
mount();
}
})();
JS;
    }
}
