<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Kk\Quiz\Iblock\Installer;

final class SectionFormAssets
{
    public static function onProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (basename($scriptName) !== 'iblock_section_edit.php') {
            return;
        }

        if (!Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
        if ($iblockId <= 0 || !self::isQuizIblock($iblockId)) {
            return;
        }

        $sectionId = (int)($_REQUEST['ID'] ?? 0);
        if ($sectionId <= 0) {
            return;
        }

        $section = self::getSection($iblockId, $sectionId);
        if ($section === null || $section['code'] === '') {
            return;
        }

        Asset::getInstance()->addString('<script>' . self::renderScript($section) . '</script>');
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

    private static function getSection(int $iblockId, int $sectionId): ?array
    {
        $section = \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $sectionId],
            false,
            ['ID', 'CODE', 'NAME']
        )->Fetch();

        if (!is_array($section)) {
            return null;
        }

        return [
            'id' => (int)$section['ID'],
            'code' => trim((string)($section['CODE'] ?? '')),
            'name' => (string)($section['NAME'] ?? ''),
        ];
    }

    private static function renderScript(array $section): string
    {
        return '(() => {'
            . 'const section = ' . self::json($section) . ';'
            . 'if (!section.id || !section.code) return;'
            . 'const tabId = "kk_quiz_embed_tab";'
            . 'const contentId = "kk_quiz_embed_content";'
            . 'const loaderComponentCode = `<?$APPLICATION->IncludeComponent("kk:quiz", ".default", [\n    "DISPLAY_MODE" => "loader"\n]);?>`;'
            . 'const blockComponentCode = `<?$APPLICATION->IncludeComponent("kk:quiz", ".default", [\n    "QUIZ_CODE" => "${section.code}",\n    "DISPLAY_MODE" => "block"\n]);?>`;'
            . 'const popupComponentCode = `<?$APPLICATION->IncludeComponent("kk:quiz", ".default", [\n    "QUIZ_CODE" => "${section.code}",\n    "DISPLAY_MODE" => "popup"\n]);?>`;'
            . 'const popupLink = `<a href="#" data-kk-quiz-popup="${section.code}">Пройти квиз</a>`;'
            . 'const popupUrl = `?kkquiz=${section.code}`;'
            . 'const copyText = (value, button) => {'
            . 'const done = () => { const original = "Скопировать"; button.textContent = "Скопировано"; setTimeout(() => { button.textContent = original; }, 1500); };'
            . 'if (navigator.clipboard && navigator.clipboard.writeText) {'
            . 'navigator.clipboard.writeText(value).then(done).catch(() => fallbackCopy(value, done));'
            . '} else {'
            . 'fallbackCopy(value, done);'
            . '}'
            . '};'
            . 'const fallbackCopy = (value, done) => {'
            . 'const textarea = document.createElement("textarea");'
            . 'textarea.value = value;'
            . 'textarea.style.position = "fixed";'
            . 'textarea.style.left = "-9999px";'
            . 'document.body.appendChild(textarea);'
            . 'textarea.focus();'
            . 'textarea.select();'
            . 'try { document.execCommand("copy"); done(); } finally { textarea.remove(); }'
            . '};'
            . 'const createExample = (title, value, description = "") => {'
            . 'const wrap = document.createElement("div");'
            . 'wrap.style.margin = "14px 0";'
            . 'const label = document.createElement("div");'
            . 'label.textContent = title;'
            . 'label.style.fontWeight = "bold";'
            . 'label.style.marginBottom = "6px";'
            . 'wrap.appendChild(label);'
            . 'if (description !== "") {'
            . 'const hint = document.createElement("div");'
            . 'hint.textContent = description;'
            . 'hint.style.margin = "0 0 8px 0";'
            . 'hint.style.color = "#555";'
            . 'wrap.appendChild(hint);'
            . '}'
            . 'const row = document.createElement("div");'
            . 'row.style.display = "flex";'
            . 'row.style.gap = "8px";'
            . 'row.style.alignItems = "flex-start";'
            . 'const code = document.createElement("pre");'
            . 'code.textContent = value;'
            . 'code.style.margin = "0";'
            . 'code.style.padding = "8px 10px";'
            . 'code.style.background = "#f5f5f5";'
            . 'code.style.border = "1px solid #d6d6d6";'
            . 'code.style.whiteSpace = "pre-wrap";'
            . 'code.style.flex = "1";'
            . 'const button = document.createElement("button");'
            . 'button.type = "button";'
            . 'button.className = "adm-btn";'
            . 'button.textContent = "Скопировать";'
            . 'button.addEventListener("click", () => copyText(value, button));'
            . 'row.appendChild(code);'
            . 'row.appendChild(button);'
            . 'wrap.appendChild(row);'
            . 'return wrap;'
            . '};'
            . 'const buildContent = () => {'
            . 'const content = document.createElement("div");'
            . 'content.id = contentId;'
            . 'content.className = "adm-detail-content-item";'
            . 'content.style.display = "none";'
            . 'content.style.padding = "16px";'
            . 'content.style.border = "1px solid #d6d6d6";'
            . 'content.style.borderTop = "0";'
            . 'content.style.background = "#fff";'
            . 'const title = document.createElement("h3");'
            . 'title.textContent = "Вставка квиза на сайт";'
            . 'title.style.marginTop = "0";'
            . 'const codeLine = document.createElement("div");'
            . 'codeLine.textContent = `Код квиза: ${section.code}`;'
            . 'codeLine.style.margin = "0 0 14px 0";'
            . 'content.appendChild(title);'
            . 'content.appendChild(codeLine);'
            . 'content.appendChild(createExample("Универсальный loader для popup", loaderComponentCode, "Добавьте этот код один раз на страницу или в шаблон сайта. После этого popup можно открывать по data-kk-quiz-popup или ?kkquiz=CODE."));'
            . 'content.appendChild(createExample("Ссылка открытия popup", popupLink, "Работает на страницах, где подключён loader-компонент или popup-компонент этого квиза."));'
            . 'content.appendChild(createExample("URL-вариант для popup", popupUrl, "Автоматически откроет popup, если на странице подключён loader-компонент или popup-компонент этого квиза."));'
            . 'content.appendChild(createExample("Блочный вывод", blockComponentCode));'
            . 'content.appendChild(createExample("Явный popup-компонент", popupComponentCode, "Альтернативный вариант: заранее вывести конкретный popup без AJAX-загрузки."));'
            . 'return content;'
            . '};'
            . 'const hideEmbedTab = () => {'
            . 'document.getElementById(contentId)?.style && (document.getElementById(contentId).style.display = "none");'
            . 'document.getElementById(tabId)?.classList.remove("adm-detail-tab-active");'
            . '};'
            . 'const showEmbedTab = () => {'
            . 'document.querySelectorAll(".adm-detail-content-item").forEach((item) => { item.style.display = "none"; });'
            . 'document.querySelectorAll(".adm-detail-tab").forEach((item) => { item.classList.remove("adm-detail-tab-active"); });'
            . 'const content = document.getElementById(contentId);'
            . 'const tab = document.getElementById(tabId);'
            . 'if (content) content.style.display = "block";'
            . 'if (tab) tab.classList.add("adm-detail-tab-active");'
            . '};'
            . 'const renderEmbedTab = () => {'
            . 'if (document.getElementById(tabId) || document.getElementById(contentId)) return;'
            . 'const tabsBlock = document.querySelector(".adm-detail-tabs-block") || document.querySelector("[id$=\"_tabs\"]");'
            . 'const form = document.querySelector("form");'
            . 'if (!tabsBlock || !form) return;'
            . 'const tab = document.createElement("span");'
            . 'tab.id = tabId;'
            . 'tab.className = "adm-detail-tab";'
            . 'tab.textContent = "Вставка на сайт";'
            . 'tab.addEventListener("click", (event) => { event.preventDefault(); showEmbedTab(); });'
            . 'tabsBlock.appendChild(tab);'
            . 'const content = buildContent();'
            . 'const insertAfter = document.querySelector(".adm-detail-content-wrap") || tabsBlock;'
            . 'insertAfter.insertAdjacentElement("afterend", content);'
            . 'tabsBlock.addEventListener("click", (event) => { if (!event.target.closest(`#${tabId}`)) hideEmbedTab(); });'
            . '};'
            . 'if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", renderEmbedTab); else renderEmbedTab();'
            . 'setTimeout(renderEmbedTab, 100);'
            . 'setTimeout(renderEmbedTab, 500);'
            . '})();';
    }

    private static function json(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
