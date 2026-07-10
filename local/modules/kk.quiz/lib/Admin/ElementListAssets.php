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

        $sectionId = self::getCurrentSectionId();
        $settings = [
            'entityTypePropertyId' => (int)$entityTypeProperty['ID'],
            'questionEnumId' => self::getEnumId((int)$entityTypeProperty['ID'], 'QUESTION'),
            'resultEnumId' => self::getEnumId((int)$entityTypeProperty['ID'], 'RESULT'),
            'quizCode' => $sectionId > 0 ? self::getSectionCode($iblockId, $sectionId) : '',
            'structureDiagnostics' => $sectionId > 0 ? QuizStructureDiagnostics::build($iblockId, $sectionId) : null,
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

    private static function getCurrentSectionId(): int
    {
        $sectionId = (int)($_REQUEST['SECTION_ID'] ?? $_REQUEST['find_section_section'] ?? 0);

        return $sectionId > 0 ? $sectionId : 0;
    }

    private static function getSectionCode(int $iblockId, int $sectionId): string
    {
        if ($iblockId <= 0 || $sectionId <= 0) {
            return '';
        }

        $section = \CIBlockSection::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'ID' => $sectionId,
            ],
            false,
            ['ID', 'CODE']
        )->Fetch();

        return is_array($section) ? trim((string)($section['CODE'] ?? '')) : '';
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
const reloadGrid = (gridId) => {
if (
window.BX
&& BX.Main
&& BX.Main.gridManager
&& typeof BX.Main.gridManager.getById === 'function'
) {
const grid = BX.Main.gridManager.getById(gridId);
const instance = grid && grid.instance ? grid.instance : grid;
if (instance && typeof instance.reloadTable === 'function') {
instance.reloadTable('POST', {
apply_filter: 'Y',
clear_nav: 'Y'
});
return true;
}
if (instance && typeof instance.reload === 'function') {
instance.reload();
return true;
}
}
return false;
};
const applyQuickFilter = (enumId) => {
const gridId = getGridId();
if (!gridId) {
console.warn('KK Quiz: grid/filter id not found');
return;
}
const propertyField = 'PROPERTY_' + settings.entityTypePropertyId;
const url = new URL('/bitrix/services/main/ajax.php', window.location.origin);
url.searchParams.set('analyticsLabel[FILTER_ID]', gridId);
url.searchParams.set('analyticsLabel[GRID_ID]', gridId);
url.searchParams.set('analyticsLabel[PRESET_ID]', 'tmp_filter');
url.searchParams.set('analyticsLabel[FIND]', 'N');
url.searchParams.set('analyticsLabel[ROWS]', 'N');
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
if (reloadGrid(gridId)) {
return;
}
const fallbackUrl = new URL(window.location.href);
fallbackUrl.searchParams.set('apply_filter', 'Y');
fallbackUrl.searchParams.set('clear_nav', 'Y');
window.location.href = fallbackUrl.toString();
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
const getAdminQuizAjaxUrl = (action) => {
const params = new URLSearchParams();
params.set('action', action);
if (window.BX && BX.bitrix_sessid) {
params.set('sessid', BX.bitrix_sessid());
}
return '/bitrix/services/main/ajax.php?' + params.toString();
};
const extractQuizFromResponse = (response) => {
if (!response || response.status !== 'success') {
return null;
}
const data = response.data;
if (!data) {
return null;
}
if (data.quiz) {
return data.quiz;
}
if (data.result && data.result.quiz) {
return data.result.quiz;
}
if (data.questions || data.results || data.code) {
return data;
}
if (Array.isArray(data)) {
for (const item of data) {
if (item && item.quiz) {
return item.quiz;
}
if (item && item.result && item.result.quiz) {
return item.result.quiz;
}
if (item && (item.questions || item.results || item.code)) {
return item;
}
}
}
return null;
};
const loadAdminQuiz = (quizCode) => {
return fetch(getAdminQuizAjaxUrl('kk:quiz.api.getQuiz'), {
method: 'POST',
credentials: 'same-origin',
headers: {
'Content-Type': 'application/json'
},
body: JSON.stringify({ quizCode })
})
.then((response) => response.json())
.then((response) => {
const quiz = extractQuizFromResponse(response);
if (!quiz) {
console.warn('KK Quiz: invalid getQuiz response', response);
throw new Error('Invalid getQuiz response');
}
return quiz;
});
};
const closeAdminQuizPreview = () => {
const modal = document.getElementById('kk-quiz-admin-preview-modal');
if (!modal) return;
modal.style.display = 'none';
const content = modal.querySelector('.kk-quiz-admin-preview-content');
if (content) content.innerHTML = '';
};
const ensurePreviewModal = () => {
let modal = document.getElementById('kk-quiz-admin-preview-modal');
if (modal) return modal;
modal = document.createElement('div');
modal.id = 'kk-quiz-admin-preview-modal';
modal.style.display = 'none';
modal.style.position = 'fixed';
modal.style.left = '0';
modal.style.top = '0';
modal.style.right = '0';
modal.style.bottom = '0';
modal.style.zIndex = '10000';
modal.style.background = 'rgba(0,0,0,.45)';
modal.innerHTML = [
'<div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(760px, calc(100vw - 40px));max-height:calc(100vh - 40px);overflow:auto;background:#fff;border-radius:6px;box-shadow:0 10px 40px rgba(0,0,0,.25);">',
'<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e5e5e5;">',
'<strong>Предпросмотр квиза</strong>',
'<button type="button" class="adm-btn kk-quiz-admin-preview-close">Закрыть</button>',
'</div>',
'<div class="kk-quiz-admin-preview-content" style="padding:16px;"></div>',
'</div>'
].join('');
document.body.appendChild(modal);
modal.addEventListener('click', (event) => {
if (event.target === modal || event.target.classList.contains('kk-quiz-admin-preview-close')) {
closeAdminQuizPreview();
}
});
document.addEventListener('keydown', (event) => {
if (event.key === 'Escape' && modal.style.display !== 'none') {
closeAdminQuizPreview();
}
});
return modal;
};
const renderAdminQuizPreview = (container, quiz) => {
const questions = Array.isArray(quiz.questions) ? quiz.questions : [];
const results = Array.isArray(quiz.results) ? quiz.results : [];
const questionMap = new Map(questions.map((question) => [String(question.id), question]));
const resultMap = new Map(results.map((result) => [String(result.id), result]));
const firstQuestion = quiz.first_question_id && questionMap.has(String(quiz.first_question_id))
? questionMap.get(String(quiz.first_question_id))
: questions[0];
const INPUT_TYPES = ['text', 'textarea', 'phone', 'email'];
const OPTION_TYPES = ['radio', 'checkbox'];
const TEMPLATE_NAMES = ['image_cards', 'cards', 'list', 'select'];
let previewState = {
answers: {}
};
const getQuestionType = (question) => {
const type = String(question.question_type || 'radio').toLowerCase();

if (type === 'select') {
return 'radio';
}

return [...OPTION_TYPES, ...INPUT_TYPES].includes(type) ? type : 'radio';
};
const getDisplayTemplate = (question) => {
const template = String(question.display_template || 'list').toLowerCase();

return TEMPLATE_NAMES.includes(template) ? template : 'list';
};
const clear = () => { container.innerHTML = ''; };
const renderHeader = () => {
const title = document.createElement('div');
title.style.fontSize = '18px';
title.style.fontWeight = 'bold';
title.style.marginBottom = '8px';
title.textContent = String(quiz.title || quiz.name || 'Квиз');
container.appendChild(title);
if (quiz.subtitle) {
const subtitle = document.createElement('div');
subtitle.style.marginBottom = '12px';
subtitle.style.color = '#666';
subtitle.textContent = String(quiz.subtitle);
container.appendChild(subtitle);
}
};
const restartPreview = () => {
previewState = {
answers: {}
};

if (firstQuestion) {
showQuestion(firstQuestion);
} else {
clear();
renderHeader();
container.appendChild(document.createTextNode('В квизе нет вопросов.'));
}
};
const appendRestartButton = () => {
const button = document.createElement('button');
button.type = 'button';
button.className = 'adm-btn';
button.style.marginTop = '12px';
button.textContent = 'Начать заново';
button.addEventListener('click', restartPreview);
container.appendChild(button);
};
const showFinalForm = () => {
clear();
renderHeader();
const message = document.createElement('div');
message.style.padding = '12px';
message.style.border = '1px solid #d6d6d6';
message.style.borderRadius = '4px';
message.textContent = 'Финальная форма заявки. В предпросмотре заявка не отправляется.';
container.appendChild(message);
appendRestartButton();
};
const showResult = (resultId) => {
clear();
renderHeader();
const result = resultMap.get(String(resultId));
const box = document.createElement('div');
box.style.padding = '12px';
box.style.border = '1px solid #d6d6d6';
box.style.borderRadius = '4px';
const title = document.createElement('div');
title.style.fontWeight = 'bold';
title.style.marginBottom = '6px';
title.textContent = result ? String(result.public_title || result.name || result.title || 'Результат') : 'Результат не найден';
box.appendChild(title);
if (result && result.text) {
const text = document.createElement('div');
text.textContent = String(result.text);
box.appendChild(text);
}
const finish = document.createElement('button');
finish.type = 'button';
finish.className = 'adm-btn';
finish.style.marginTop = '12px';
finish.textContent = 'Перейти к финальной форме';
finish.addEventListener('click', showFinalForm);
box.appendChild(finish);
container.appendChild(box);
appendRestartButton();
};
const goNext = (question, answer) => {
const answerResultId = answer && (answer.result_id || answer.score_result_id) ? (answer.result_id || answer.score_result_id) : 0;
if (answerResultId) {
showResult(answerResultId);
return;
}
const nextQuestionId = answer && answer.next_question_id ? answer.next_question_id : question.default_next_question_id;
if (nextQuestionId && questionMap.has(String(nextQuestionId))) {
showQuestion(questionMap.get(String(nextQuestionId)));
return;
}
if (question.default_result_id) {
showResult(question.default_result_id);
return;
}
showFinalForm();
};
const renderRadioButtons = (question, answers) => {
answers.forEach((answer) => {
const button = document.createElement('button');
button.type = 'button';
button.className = 'adm-btn';
button.style.display = 'block';
button.style.margin = '6px 0';
button.textContent = String(answer.text || 'Ответ');
button.addEventListener('click', () => {
previewState.answers[question.id] = answer;
goNext(question, answer);
});
container.appendChild(button);
});
};
const renderSelectChoice = (question, answers) => {
const select = document.createElement('select');
select.className = 'adm-select';
select.style.minWidth = '280px';

const placeholder = document.createElement('option');
placeholder.value = '';
placeholder.textContent = 'Выберите вариант';
select.appendChild(placeholder);

answers.forEach((answer, index) => {
const option = document.createElement('option');
option.value = String(index);
option.textContent = String(answer.text || 'Ответ');
select.appendChild(option);
});

const next = document.createElement('button');
next.type = 'button';
next.className = 'adm-btn';
next.style.marginLeft = '8px';
next.textContent = 'Далее';

next.addEventListener('click', () => {
const index = Number.parseInt(select.value, 10);
if (!Number.isFinite(index) || !answers[index]) {
select.focus();
return;
}

const answer = answers[index];
previewState.answers[question.id] = answer;
goNext(question, answer);
});

container.appendChild(select);
container.appendChild(next);
};
const renderCheckboxes = (question, answers) => {
const selected = new Set();
const wrap = document.createElement('div');

answers.forEach((answer, index) => {
const label = document.createElement('label');
label.style.display = 'block';
label.style.margin = '6px 0';

const input = document.createElement('input');
input.type = 'checkbox';
input.value = String(index);

input.addEventListener('change', () => {
if (input.checked) {
selected.add(index);
} else {
selected.delete(index);
}
});

label.appendChild(input);
label.appendChild(document.createTextNode(' ' + String(answer.text || 'Ответ')));
wrap.appendChild(label);
});

const next = document.createElement('button');
next.type = 'button';
next.className = 'adm-btn';
next.style.marginTop = '8px';
next.textContent = 'Далее';

next.addEventListener('click', () => {
if (question.is_required === true && selected.size === 0) {
const firstInput = wrap.querySelector('input[type="checkbox"]');
if (firstInput) {
firstInput.focus();
}
return;
}

previewState.answers[question.id] = [...selected].map((index) => answers[index]).filter(Boolean);
goNext(question, null);
});

container.appendChild(wrap);
container.appendChild(next);
};
const renderInputQuestion = (question, type) => {
const label = document.createElement('label');
label.style.display = 'block';

const input = type === 'textarea'
? document.createElement('textarea')
: document.createElement('input');

input.className = 'adm-input';
input.style.minWidth = '320px';
input.required = question.is_required === true;
input.placeholder = String(question.placeholder || '');

if (input.tagName === 'INPUT') {
input.type = type === 'phone' ? 'tel' : type === 'email' ? 'email' : 'text';
}

if (type === 'textarea') {
input.rows = 4;
input.style.width = '100%';
input.style.maxWidth = '520px';
}

label.appendChild(input);
container.appendChild(label);

const next = document.createElement('button');
next.type = 'button';
next.className = 'adm-btn';
next.style.marginTop = '8px';
next.textContent = 'Далее';

next.addEventListener('click', () => {
const value = String(input.value || '').trim();

if (input.required && value === '') {
input.focus();
return;
}

if (type === 'email' && value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
input.focus();
return;
}

previewState.answers[question.id] = value;
goNext(question, null);
});

container.appendChild(next);
};
const showQuestion = (question) => {
clear();
renderHeader();
const questionTitle = document.createElement('div');
questionTitle.style.fontSize = '16px';
questionTitle.style.fontWeight = 'bold';
questionTitle.style.marginBottom = '10px';
questionTitle.textContent = String(question.public_title || question.name || question.title || 'Вопрос');
container.appendChild(questionTitle);
const type = getQuestionType(question);
const template = getDisplayTemplate(question);
const answers = Array.isArray(question.answers) ? question.answers : [];

if (INPUT_TYPES.includes(type)) {
renderInputQuestion(question, type);
appendRestartButton();
return;
}

if (answers.length === 0) {
const empty = document.createElement('div');
empty.style.color = '#6b4e00';
empty.textContent = 'У вопроса нет вариантов ответа.';
container.appendChild(empty);
const next = document.createElement('button');
next.type = 'button';
next.className = 'adm-btn';
next.style.marginTop = '10px';
next.textContent = 'Дальше';
next.addEventListener('click', () => goNext(question, null));
container.appendChild(next);
appendRestartButton();
return;
}

if (type === 'checkbox') {
renderCheckboxes(question, answers);
appendRestartButton();
return;
}

if (type === 'radio' && template === 'select') {
renderSelectChoice(question, answers);
appendRestartButton();
return;
}

renderRadioButtons(question, answers);
appendRestartButton();
};
if (firstQuestion) {
showQuestion(firstQuestion);
} else {
clear();
renderHeader();
container.appendChild(document.createTextNode('В квизе нет вопросов.'));
}
};
const openAdminQuizPreview = (quizCode) => {
const modal = ensurePreviewModal();
const content = modal.querySelector('.kk-quiz-admin-preview-content');
if (!content) return;
modal.style.display = 'block';
content.innerHTML = '<div style="padding:20px;text-align:center;">Загрузка квиза...</div>';
loadAdminQuiz(quizCode)
.then((quiz) => { renderAdminQuizPreview(content, quiz); })
.catch((error) => {
content.innerHTML = '<div style="color:#a40000;">Не удалось загрузить квиз. Проверьте консоль и Network-ответ getQuiz.</div>';
console.warn('KK Quiz: preview load failed', { quizCode, error });
});
};
const createPanel = (id) => {
const block = document.createElement('div');
block.id = id;
block.style.margin = id === 'kk-quiz-element-list-help' ? '0 0 12px 0' : '12px 0 0 0';
block.style.padding = '10px 12px';
block.style.border = '1px solid #d6d6d6';
block.style.borderRadius = '4px';
block.style.background = '#f7f7f7';
block.style.color = '#333';
return block;
};
const createTopBlock = () => {
const block = createPanel('kk-quiz-element-list-help');
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
if (settings.quizCode) {
const preview = document.createElement('div');
preview.style.marginTop = '6px';
preview.style.marginBottom = '6px';
preview.appendChild(document.createTextNode('Предпросмотр: '));
const button = document.createElement('button');
button.type = 'button';
button.className = 'adm-btn';
button.textContent = 'Пройти квиз';
button.addEventListener('click', () => openAdminQuizPreview(settings.quizCode));
preview.appendChild(button);
block.appendChild(preview);
}
const legend = document.createElement('div');
legend.textContent = 'Q — вопрос, R — результат. NAME — техническое название для админки. “Заголовок на сайте” — текст, который видит пользователь.';
block.appendChild(legend);
return block;
};
const createBadge = (type) => {
const badge = document.createElement('span');
badge.textContent = type === 'result' ? 'R' : 'Q';
badge.style.display = 'inline-block';
badge.style.minWidth = '18px';
badge.style.marginRight = '6px';
badge.style.padding = '1px 4px';
badge.style.borderRadius = '3px';
badge.style.textAlign = 'center';
badge.style.fontWeight = 'bold';
badge.style.background = type === 'result' ? '#e5f6e5' : '#e8eef8';
badge.style.color = type === 'result' ? '#267000' : '#245493';
return badge;
};
const appendEditLink = (line, node) => {
if (!node || !node.edit_url) return;
const link = document.createElement('a');
link.href = String(node.edit_url);
link.textContent = ' ✎';
link.title = 'Редактировать элемент';
link.target = '_blank';
link.rel = 'noopener noreferrer';
link.style.marginLeft = '4px';
link.style.textDecoration = 'none';
line.appendChild(link);
};
const appendDiagnosticsSection = (block, diagnostics) => {
if (!diagnostics || !Array.isArray(diagnostics.items) || diagnostics.items.length === 0) return;
const diagnosticsBlock = document.createElement('div');
const diagnosticsTitle = document.createElement('div');
diagnosticsTitle.textContent = 'KK Quiz — проверка структуры';
diagnosticsTitle.style.fontWeight = 'bold';
diagnosticsTitle.style.marginBottom = '4px';
diagnosticsBlock.appendChild(diagnosticsTitle);
diagnostics.items.forEach((item) => {
const line = document.createElement('div');
line.style.margin = '3px 0';
line.style.color = item.type === 'error' ? '#a40000' : (item.type === 'warning' ? '#6b4e00' : '#267000');
line.textContent = (item.type === 'error' ? '✕ ' : (item.type === 'warning' ? '⚠ ' : '✓ ')) + String(item.message || '');
diagnosticsBlock.appendChild(line);
});
block.appendChild(diagnosticsBlock);
};
const appendGraphSection = (block, diagnostics) => {
const graph = diagnostics && diagnostics.graph ? diagnostics.graph : null;
if (!graph || !Array.isArray(graph.nodes) || graph.nodes.length === 0) return;
const maxNodes = 40;
let renderedNodes = 0;
let truncated = false;
const nodeMap = new Map(graph.nodes.map((node) => [String(node.id), node]));
const edgesByFrom = new Map();
(graph.edges || []).forEach((edge) => {
const key = String(edge.from);
if (!edgesByFrom.has(key)) edgesByFrom.set(key, []);
edgesByFrom.get(key).push(edge);
});
const graphBlock = document.createElement('div');
graphBlock.style.marginTop = '10px';
graphBlock.style.paddingTop = '10px';
graphBlock.style.borderTop = '1px solid #d6d6d6';
const graphTitle = document.createElement('div');
graphTitle.textContent = 'KK Quiz — схема';
graphTitle.style.fontWeight = 'bold';
graphTitle.style.marginBottom = '6px';
graphBlock.appendChild(graphTitle);
const renderNodeLabel = (node) => {
const line = document.createElement('div');
line.appendChild(createBadge(node.type));
const title = document.createElement('span');
title.textContent = (node.is_start ? '★ ' : '') + String(node.title || ('ID ' + node.id));
title.style.fontWeight = node.type === 'question' ? 'bold' : 'normal';
line.appendChild(title);
appendEditLink(line, node);
return line;
};
const renderFlowBranch = (node, depth, visited) => {
const container = document.createElement('div');
container.style.margin = depth === 0 ? '8px 0' : '5px 0 5px 22px';
container.style.padding = '6px 8px';
container.style.border = '1px solid #e3e3e3';
container.style.borderRadius = '4px';
container.style.background = '#fff';
container.style.opacity = node.is_reachable === false ? '0.55' : '1';
container.appendChild(renderNodeLabel(node));
if (renderedNodes >= maxNodes) {
truncated = true;
return container;
}
renderedNodes += 1;
if (node.type === 'result') return container;
const nodeKey = String(node.id);
if (visited.has(nodeKey)) {
const cycle = document.createElement('div');
cycle.style.marginLeft = '26px';
cycle.style.color = '#6b4e00';
cycle.textContent = '↳ уже показан выше';
container.appendChild(cycle);
return container;
}
const nextVisited = new Set(visited);
nextVisited.add(nodeKey);
const edges = edgesByFrom.get(nodeKey) || [];
if (edges.length === 0) {
const empty = document.createElement('div');
empty.style.marginLeft = '26px';
empty.style.color = '#6b4e00';
empty.textContent = 'Нет переходов';
container.appendChild(empty);
return container;
}
edges.forEach((edge, index) => {
const edgeLine = document.createElement('div');
edgeLine.style.margin = '4px 0 0 26px';
edgeLine.style.color = edge.is_broken ? '#a40000' : '#555';
const prefix = index === edges.length - 1 ? '└─ ' : '├─ ';
edgeLine.appendChild(document.createTextNode(prefix + String(edge.label || 'Ответ') + ' → '));
if (edge.is_broken) {
edgeLine.appendChild(document.createTextNode(String(edge.to_title || ('ID ' + edge.to + ' не найден'))));
container.appendChild(edgeLine);
return;
}
const target = nodeMap.get(String(edge.to));
if (!target) return;
edgeLine.appendChild(createBadge(edge.to_type || target.type));
const targetTitle = document.createElement('span');
targetTitle.textContent = String(edge.to_title || target.title || ('ID ' + edge.to));
edgeLine.appendChild(targetTitle);
appendEditLink(edgeLine, target);
container.appendChild(edgeLine);
if (target.type === 'result') {
return;
}
if (renderedNodes < maxNodes) {
container.appendChild(renderFlowBranch(target, depth + 1, nextVisited));
} else {
truncated = true;
}
});
return container;
};
const startNode = graph.nodes.find((node) => node.is_start === true) || graph.nodes.find((node) => node.type === 'question') || graph.nodes[0];
if (startNode) graphBlock.appendChild(renderFlowBranch(startNode, 0, new Set()));
const unreachable = graph.nodes.filter((node) => node.is_reachable === false);
if (unreachable.length > 0) {
const title = document.createElement('div');
title.textContent = 'Недостижимые элементы';
title.style.fontWeight = 'bold';
title.style.margin = '10px 0 4px';
graphBlock.appendChild(title);
unreachable.forEach((node) => {
const line = document.createElement('div');
line.style.opacity = '0.55';
line.style.margin = '4px 0';
line.appendChild(createBadge(node.type));
const itemTitle = document.createElement('span');
itemTitle.textContent = String(node.title || ('ID ' + node.id));
line.appendChild(itemTitle);
appendEditLink(line, node);
graphBlock.appendChild(line);
});
}
if (truncated || graph.nodes.length > maxNodes) {
const notice = document.createElement('div');
notice.textContent = 'Схема сокращена: показаны первые 40 элементов.';
notice.style.color = '#6b4e00';
notice.style.marginTop = '6px';
graphBlock.appendChild(notice);
}
block.appendChild(graphBlock);
};
const createDetailsBlock = () => {
const block = createPanel('kk-quiz-element-list-details');
const diagnostics = settings.structureDiagnostics;
appendDiagnosticsSection(block, diagnostics);
appendGraphSection(block, diagnostics);
return block;
};
const insertTopBlock = () => {
if (document.getElementById('kk-quiz-element-list-help')) return;
const anchor = findAnchor();
if (!anchor || !anchor.parentNode) return;
anchor.parentNode.insertBefore(createTopBlock(), anchor);
};
const insertDetailsBlock = () => {
if (!settings.structureDiagnostics || document.getElementById('kk-quiz-element-list-details')) return;
const anchor = findAnchor();
if (!anchor || !anchor.parentNode) return;
anchor.insertAdjacentElement('afterend', createDetailsBlock());
};
const refreshElementListHelp = () => {
insertTopBlock();
insertDetailsBlock();
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
