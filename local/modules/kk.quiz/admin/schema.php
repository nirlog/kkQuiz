<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

global $APPLICATION, $USER;

$APPLICATION->SetTitle('KK Quiz — схема квиза');

if (!Loader::includeModule('kk.quiz') || !Loader::includeModule('iblock')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Модуль kk.quiz или iblock не установлен.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}
if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
}

$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$sectionId = (int)($_GET['ID'] ?? 0);
$iblock = CIBlock::GetList([], ['TYPE' => Installer::IBLOCK_TYPE_ID, 'CODE' => Installer::QUIZZES_IBLOCK_CODE])->Fetch();
$iblockId = is_array($iblock) ? (int)$iblock['ID'] : 0;
$section = $sectionId > 0 && $iblockId > 0
    ? CIBlockSection::GetList([], ['ID' => $sectionId, 'IBLOCK_ID' => $iblockId], false, ['ID', 'NAME', 'CODE', 'UF_KK_START_QUESTION'])->Fetch()
    : false;
$listUrl = 'kk_quiz_quizzes.php?' . http_build_query(['lang' => $lang]);

if (!is_array($section)) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    CAdminMessage::ShowMessage('Квиз не найден.');
    echo '<p><a class="adm-btn" href="' . htmlspecialcharsbx($listUrl) . '">К списку квизов</a></p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

$quizCode = (string)$section['CODE'];
$elementEditUrl = static fn (int $id): string => 'iblock_element_edit.php?' . http_build_query([
    'IBLOCK_ID' => $iblockId,
    'type' => Installer::IBLOCK_TYPE_ID,
    'ID' => $id,
    'find_section_section' => $sectionId,
    'SECTION_ID' => $sectionId,
    'lang' => $lang,
]);
$propertyValue = static function (array $properties, string $code): mixed {
    return $properties[$code]['VALUE'] ?? null;
};
$propertyPanelValue = static function (array $properties, string $code): string {
    $property = $properties[$code] ?? [];
    $value = $property['VALUE_XML_ID'] ?? $property['VALUE'] ?? '';
    if (is_array($value)) {
        $value = reset($value);
    }
    return is_scalar($value) ? trim((string)$value) : '';
};
$positiveInt = static fn (mixed $value): int => max(0, (int)(is_array($value) ? reset($value) : $value));
$decodeAnswers = static function (mixed $value): array {
    $invalid = false;
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return ['answers' => [], 'invalid' => false];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return ['answers' => [], 'invalid' => true];
        }
        $value = $decoded;
    }
    if (!is_array($value)) {
        return ['answers' => [], 'invalid' => false];
    }
    $rows = $value['rows'] ?? $value;
    if (!is_array($rows)) {
        return ['answers' => [], 'invalid' => true];
    }
    if (isset($rows['text']) || isset($rows['TEXT'])) {
        $rows = [$rows];
    }
    $answers = [];
    foreach ($rows as $row) {
        if (is_string($row)) {
            $decoded = json_decode(trim($row), true);
            if (!is_array($decoded)) {
                $invalid = true;
                continue;
            }
            $row = $decoded;
        }
        if (is_array($row)) {
            $answers[] = $row;
        } else {
            $invalid = true;
        }
    }
    return ['answers' => $answers, 'invalid' => $invalid];
};

$questions = [];
$results = [];
$issues = [];
$elements = CIBlockElement::GetList(
    ['SORT' => 'ASC', 'ID' => 'ASC'],
    ['IBLOCK_ID' => $iblockId, 'SECTION_ID' => $sectionId, 'INCLUDE_SUBSECTIONS' => 'N'],
    false,
    false,
    ['ID', 'IBLOCK_ID', 'CODE', 'ACTIVE', 'NAME', 'SORT']
);
while ($element = $elements->GetNextElement()) {
    $fields = $element->GetFields();
    $properties = $element->GetProperties();
    $id = (int)$fields['ID'];
    $type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE_XML_ID'] ?? ''));
    if ($type === '') {
        $type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE'] ?? ''));
    }
    if (!in_array($type, ['QUESTION', 'RESULT'], true)) {
        $issues[] = ['type' => 'warning', 'node_id' => '', 'message' => 'Элемент ID ' . $id . ' без типа QUESTION/RESULT.'];
        continue;
    }
    $publicTitle = trim((string)$propertyValue($properties, 'KK_PUBLIC_TITLE'));
    $item = [
        'id' => $id,
        'code' => (string)($fields['CODE'] ?? ''),
        'name' => (string)($fields['NAME'] ?? ''),
        'title' => $publicTitle !== '' ? $publicTitle : (string)($fields['NAME'] ?? ''),
        'active' => ($fields['ACTIVE'] ?? 'N') === 'Y',
        'sort' => (int)($fields['SORT'] ?? 0),
        'properties' => $properties,
        'edit_url' => $elementEditUrl($id),
    ];
    if ($type === 'QUESTION') {
        $decoded = $decodeAnswers($propertyValue($properties, 'KK_ANSWERS'));
        $item['answers'] = $decoded['answers'];
        $item['answers_invalid'] = $decoded['invalid'];
        $item['question_type'] = strtolower((string)($properties['KK_QUESTION_TYPE']['VALUE_XML_ID'] ?? ''));
        $item['default_next_question_id'] = $positiveInt($propertyValue($properties, 'KK_DEFAULT_NEXT_QUESTION'));
        $item['default_result_id'] = $positiveInt($propertyValue($properties, 'KK_DEFAULT_RESULT'));
        $item['answers_for_panel'] = [];
        foreach ($decoded['answers'] as $answer) {
            $nextQuestionId = $positiveInt($answer['next_question_id'] ?? $answer['NEXT_QUESTION_ID'] ?? 0);
            $nextQuestionCode = trim((string)($answer['next_question_code'] ?? $answer['NEXT_QUESTION_CODE'] ?? ''));
            $resultId = $positiveInt($answer['result_id'] ?? $answer['RESULT_ID'] ?? 0);
            $resultCode = trim((string)($answer['result_code'] ?? $answer['RESULT_CODE'] ?? ''));
            $scoreResultId = $positiveInt($answer['score_result_id'] ?? $answer['SCORE_RESULT_ID'] ?? 0);
            $scoreResultCode = trim((string)($answer['score_result_code'] ?? $answer['SCORE_RESULT_CODE'] ?? ''));
            $item['answers_for_panel'][] = [
                'text' => trim((string)($answer['text'] ?? $answer['TEXT'] ?? '')),
                'code' => trim((string)($answer['code'] ?? $answer['CODE'] ?? '')),
                'active' => in_array($answer['active'] ?? $answer['ACTIVE'] ?? 'N', [true, 1, '1', 'Y'], true),
                'sort' => (int)($answer['sort'] ?? $answer['SORT'] ?? 0),
                'next_question_id' => $nextQuestionId,
                'next_question_code' => $nextQuestionCode,
                'result_id' => $resultId,
                'result_code' => $resultCode,
                'score_result_id' => $scoreResultId,
                'score_result_code' => $scoreResultCode,
                'score_value' => (int)($answer['score_value'] ?? $answer['SCORE_VALUE'] ?? 0),
                'has_navigation_target' => $nextQuestionId > 0 || $nextQuestionCode !== '' || $resultId > 0 || $resultCode !== '',
                'has_score_target' => $scoreResultId > 0 || $scoreResultCode !== '',
            ];
        }
        $questions[$id] = $item;
    } else {
        $item['result_meta_for_panel'] = array_filter([
            'cta_text' => $propertyPanelValue($properties, 'KK_RESULT_CTA_TEXT'),
            'cta_url' => $propertyPanelValue($properties, 'KK_RESULT_CTA_LINK'),
            'cta_target' => $propertyPanelValue($properties, 'KK_RESULT_CTA_TARGET'),
            'show_form' => $propertyPanelValue($properties, 'KK_RESULT_SHOW_FORM'),
            'video_url' => $propertyPanelValue($properties, 'KK_RESULT_VIDEO_URL'),
        ], static fn (string $value): bool => $value !== '');
        $results[$id] = $item;
    }
}

$questionCodeIds = [];
$resultCodeIds = [];
foreach ($questions as $id => $question) {
    if ($question['code'] !== '') {
        $questionCodeIds[$question['code']] = $id;
    }
}
foreach ($results as $id => $result) {
    if ($result['code'] !== '') {
        $resultCodeIds[$result['code']] = $id;
    }
}
$resolveId = static function (array $row, string $idKey, string $codeKey, array $codeIds) use ($positiveInt): int {
    $id = $positiveInt($row[$idKey] ?? $row[strtoupper($idKey)] ?? 0);
    if ($id > 0) {
        return $id;
    }
    $code = trim((string)($row[$codeKey] ?? $row[strtoupper($codeKey)] ?? ''));
    return (int)($codeIds[$code] ?? 0);
};
$edges = [];
$addEdge = static function (string $from, string $to, string $label, string $type, bool $broken = false) use (&$edges): void {
    $edges[] = ['from' => $from, 'to' => $to, 'label' => $label, 'type' => $type, 'broken' => $broken];
};

foreach ($questions as $questionId => $question) {
    $nodeId = 'question_' . $questionId;
    if ($question['answers_invalid']) {
        $issues[] = ['type' => 'warning', 'node_id' => $nodeId, 'message' => 'У вопроса «' . $question['title'] . '» некорректный JSON ответов.'];
    }
    if (in_array($question['question_type'], ['radio', 'checkbox', ''], true) && $question['answers'] === []) {
        $issues[] = ['type' => 'warning', 'node_id' => $nodeId, 'message' => 'Вопрос «' . $question['title'] . '» без ответов.'];
    }
    $defaultQuestionId = $positiveInt($propertyValue($question['properties'], 'KK_DEFAULT_NEXT_QUESTION'));
    if ($defaultQuestionId > 0) {
        $broken = !isset($questions[$defaultQuestionId]);
        $addEdge($nodeId, 'question_' . $defaultQuestionId, 'по умолчанию', 'default_question', $broken);
        if ($broken) {
            $issues[] = ['type' => 'error', 'node_id' => $nodeId, 'message' => 'Битая ссылка вопроса «' . $question['title'] . '» на вопрос ID ' . $defaultQuestionId . '.'];
        }
    }
    $defaultResultId = $positiveInt($propertyValue($question['properties'], 'KK_DEFAULT_RESULT'));
    if ($defaultResultId > 0) {
        $broken = !isset($results[$defaultResultId]);
        $addEdge($nodeId, 'result_' . $defaultResultId, 'результат по умолчанию', 'default_result', $broken);
        if ($broken) {
            $issues[] = ['type' => 'error', 'node_id' => $nodeId, 'message' => 'Битая ссылка вопроса «' . $question['title'] . '» на результат ID ' . $defaultResultId . '.'];
        }
    }
    $hasQuestionDefaultNavigation = $defaultQuestionId > 0 || $defaultResultId > 0;
    foreach ($question['answers'] as $index => $answer) {
        $answerText = trim((string)($answer['text'] ?? $answer['TEXT'] ?? '')) ?: 'Ответ #' . ($index + 1);
        $nextId = $resolveId($answer, 'next_question_id', 'next_question_code', $questionCodeIds);
        $resultId = $resolveId($answer, 'result_id', 'result_code', $resultCodeIds);
        $scoreResultId = $resolveId($answer, 'score_result_id', 'score_result_code', $resultCodeIds);
        $nextRequested = $positiveInt($answer['next_question_id'] ?? $answer['NEXT_QUESTION_ID'] ?? 0) > 0 || trim((string)($answer['next_question_code'] ?? $answer['NEXT_QUESTION_CODE'] ?? '')) !== '';
        $resultRequested = $positiveInt($answer['result_id'] ?? $answer['RESULT_ID'] ?? 0) > 0 || trim((string)($answer['result_code'] ?? $answer['RESULT_CODE'] ?? '')) !== '';
        $scoreRequested = $positiveInt($answer['score_result_id'] ?? $answer['SCORE_RESULT_ID'] ?? 0) > 0 || trim((string)($answer['score_result_code'] ?? $answer['SCORE_RESULT_CODE'] ?? '')) !== '';
        $hasTarget = false;
        if ($nextId > 0) {
            $hasTarget = true;
            $broken = !isset($questions[$nextId]);
            $addEdge($nodeId, 'question_' . $nextId, $answerText, 'answer', $broken);
            if ($broken) {
                $issues[] = ['type' => 'error', 'node_id' => $nodeId, 'message' => 'Ответ «' . $answerText . '» ведёт на отсутствующий вопрос ID ' . $nextId . '.'];
            }
        }
        if ($resultId > 0) {
            $hasTarget = true;
            $broken = !isset($results[$resultId]);
            $addEdge($nodeId, 'result_' . $resultId, $answerText, 'result', $broken);
            if ($broken) {
                $issues[] = ['type' => 'error', 'node_id' => $nodeId, 'message' => 'Ответ «' . $answerText . '» ведёт на отсутствующий результат ID ' . $resultId . '.'];
            }
        }
        if ($scoreResultId > 0) {
            $hasTarget = true;
            $score = (int)($answer['score_value'] ?? $answer['SCORE_VALUE'] ?? 0);
            $broken = !isset($results[$scoreResultId]);
            $addEdge($nodeId, 'result_' . $scoreResultId, $answerText . ' / баллы: ' . $score, 'score', $broken);
            if ($broken) {
                $issues[] = ['type' => 'error', 'node_id' => $nodeId, 'message' => 'Scoring-ответ «' . $answerText . '» ведёт на отсутствующий результат ID ' . $scoreResultId . '.'];
            }
        }
        if ($nextRequested && $nextId === 0) {
            $hasTarget = true;
            $issues[] = ['type' => 'error', 'node_id' => $nodeId, 'message' => 'Ответ «' . $answerText . '» содержит битую ссылку на вопрос.'];
        }
        if ($resultRequested && $resultId === 0) {
            $hasTarget = true;
            $issues[] = ['type' => 'error', 'node_id' => $nodeId, 'message' => 'Ответ «' . $answerText . '» содержит битую ссылку на результат.'];
        }
        if ($scoreRequested && $scoreResultId === 0) {
            $hasTarget = true;
            $issues[] = ['type' => 'error', 'node_id' => $nodeId, 'message' => 'Scoring-ответ «' . $answerText . '» содержит битую ссылку на результат.'];
        }
        if (!$hasTarget && !$hasQuestionDefaultNavigation) {
            $issues[] = ['type' => 'warning', 'node_id' => $nodeId, 'message' => 'Ответ «' . $answerText . '» вопроса «' . $question['title'] . '» без перехода.'];
        }
    }
}

$configuredStartId = $positiveInt($section['UF_KK_START_QUESTION'] ?? 0);
$startId = 0;
if ($configuredStartId > 0) {
    if (isset($questions[$configuredStartId])) {
        $startId = $configuredStartId;
        $addEdge('start', 'question_' . $startId, 'старт', 'start');
    } else {
        $issues[] = ['type' => 'error', 'node_id' => 'start', 'message' => 'Стартовый вопрос ID ' . $configuredStartId . ' не найден.'];
    }
} else {
    foreach ($questions as $questionId => $question) {
        if ($question['active']) {
            $startId = $questionId;
            $addEdge('start', 'question_' . $startId, 'первый активный', 'start');
            break;
        }
    }
    if ($startId === 0) {
        $issues[] = ['type' => 'error', 'node_id' => 'start', 'message' => 'Нет стартового или первого активного вопроса.'];
    }
}

$isNavigationEdge = static function (array $edge): bool {
    return in_array((string)($edge['type'] ?? ''), ['start', 'default_question', 'default_result', 'answer', 'result'], true);
};
$isScoreEdge = static fn (array $edge): bool => (string)($edge['type'] ?? '') === 'score';
$navigationAdjacency = [];
$navigationIncoming = [];
$scoreIncoming = [];
foreach ($edges as $edge) {
    if ($edge['broken']) {
        continue;
    }
    if ($isNavigationEdge($edge)) {
        $navigationAdjacency[$edge['from']][] = $edge['to'];
        $navigationIncoming[$edge['to']] = (int)($navigationIncoming[$edge['to']] ?? 0) + 1;
    }
    if ($isScoreEdge($edge)) {
        $scoreIncoming[$edge['to']] = (int)($scoreIncoming[$edge['to']] ?? 0) + 1;
    }
}
$incomingConditions = [];
foreach ($edges as $edge) {
    if (!empty($edge['broken'])) {
        continue;
    }
    $targetId = (string)($edge['to'] ?? '');
    if ($targetId === '' || $targetId === 'start') {
        continue;
    }
    $label = trim((string)($edge['label'] ?? ''));
    if ($label === '') {
        continue;
    }
    $incomingConditions[$targetId][] = [
        'from' => (string)($edge['from'] ?? ''),
        'label' => $label,
        'type' => (string)($edge['type'] ?? ''),
    ];
}
$levels = ['start' => 0];
$queue = ['start'];
while ($queue !== []) {
    $from = array_shift($queue);
    foreach ($navigationAdjacency[$from] ?? [] as $to) {
        $nextLevel = $levels[$from] + 1;
        if (!isset($levels[$to]) || $nextLevel < $levels[$to]) {
            $levels[$to] = $nextLevel;
            $queue[] = $to;
        }
    }
}
$hasCycle = false;
$visited = [];
$path = [];
$detectCycle = static function (string $node) use (&$detectCycle, &$visited, &$path, &$hasCycle, $navigationAdjacency): void {
    if (isset($path[$node])) {
        $hasCycle = true;
        return;
    }
    if (isset($visited[$node])) {
        return;
    }
    $visited[$node] = true;
    $path[$node] = true;
    foreach ($navigationAdjacency[$node] ?? [] as $next) {
        $detectCycle($next);
    }
    unset($path[$node]);
};
$detectCycle('start');
if ($hasCycle) {
    $issues[] = ['type' => 'warning', 'node_id' => '', 'message' => 'Обнаружен потенциальный цикл переходов.'];
}

$maxNavigationLevel = max(array_values($levels) ?: [0]);
$scoreResultsLevel = $maxNavigationLevel + 1;
$orphanLevel = $maxNavigationLevel + 2;
$shortText = static function (string $text, int $limit = 70): string {
    $text = trim($text);
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit - 1) . '…';
};
$sourceTitles = ['start' => 'Старт'];
foreach ($questions as $id => $question) {
    $sourceTitles['question_' . $id] = $question['title'];
}
foreach ($results as $id => $result) {
    $sourceTitles['result_' . $id] = $result['title'];
}
$buildIncomingData = static function (string $nodeId) use ($incomingConditions, $sourceTitles, $shortText): array {
    $conditions = $incomingConditions[$nodeId] ?? [];
    if ($conditions === []) {
        return ['incoming_conditions' => [], 'incoming_count' => 0, 'incoming_preview' => '', 'incoming_title' => ''];
    }
    $lines = [];
    foreach ($conditions as $condition) {
        $sourceTitle = (string)($sourceTitles[$condition['from']] ?? $condition['from']);
        $label = (string)$condition['label'];
        switch ($condition['type']) {
            case 'start':
                $lines[] = 'Стартовый вопрос';
                break;
            case 'default_question':
                $lines[] = 'По умолчанию из «' . $sourceTitle . '»';
                break;
            case 'default_result':
                $lines[] = 'Результат по умолчанию из «' . $sourceTitle . '»';
                break;
            case 'score':
                $lines[] = 'Баллы: «' . $label . '» из «' . $sourceTitle . '»';
                break;
            default:
                $lines[] = 'При ответе: «' . $label . '» из «' . $sourceTitle . '»';
        }
    }
    $count = count($conditions);
    if ($count === 1) {
        $condition = $conditions[0];
        $preview = match ($condition['type']) {
            'start' => 'Стартовый вопрос',
            'default_question' => 'По умолчанию',
            'default_result' => 'Результат по умолчанию',
            'score' => 'Баллы: ' . $condition['label'],
            default => 'При ответе: ' . $condition['label'],
        };
    } else {
        $labels = array_values(array_unique(array_column($conditions, 'label')));
        $types = array_values(array_unique(array_column($conditions, 'type')));
        if (count($labels) === 1 && count($types) === 1) {
            $preview = match ($types[0]) {
                'start' => 'Стартовый вопрос',
                'default_question' => 'По умолчанию',
                'default_result' => 'Результат по умолчанию',
                'score' => 'Баллы: ' . $labels[0],
                default => 'При ответе: ' . $labels[0],
            };
        } else {
            $preview = 'Входящих условий: ' . $count;
        }
    }
    return [
        'incoming_conditions' => $conditions,
        'incoming_count' => $count,
        'incoming_preview' => $shortText($preview),
        'incoming_title' => implode("\n", $lines),
    ];
};
$nodes = [array_merge(['id' => 'start', 'entity_id' => 0, 'type' => 'start', 'code' => '', 'name' => 'Старт', 'title' => 'Старт', 'active' => true, 'sort' => 0, 'answers_count' => 0, 'edit_url' => '', 'level' => 0, 'unreachable' => false, 'score_only' => false], $buildIncomingData('start'))];
foreach ($questions as $id => $question) {
    $nodeId = 'question_' . $id;
    $unreachable = !isset($levels[$nodeId]);
    if ($unreachable) {
        $issues[] = ['type' => 'warning', 'node_id' => $nodeId, 'message' => 'Вопрос «' . $question['title'] . '» недостижим от старта.'];
    }
    if (!$question['active'] && isset($navigationIncoming[$nodeId])) {
        $issues[] = ['type' => 'warning', 'node_id' => $nodeId, 'message' => 'В активной цепочке есть переход к неактивному вопросу «' . $question['title'] . '».'];
    }
    $nodes[] = array_merge($question, ['id' => $nodeId, 'entity_id' => $id, 'type' => 'question', 'answers_count' => count($question['answers']), 'level' => $levels[$nodeId] ?? $orphanLevel, 'unreachable' => $unreachable, 'score_only' => false], $buildIncomingData($nodeId));
}
foreach ($results as $id => $result) {
    $nodeId = 'result_' . $id;
    $hasNavigationIncoming = isset($navigationIncoming[$nodeId]);
    $hasScoreIncoming = isset($scoreIncoming[$nodeId]);
    $scoreOnly = !isset($levels[$nodeId]) && $hasScoreIncoming;
    $unreachable = !isset($levels[$nodeId]) && !$hasScoreIncoming;
    $level = $levels[$nodeId] ?? ($scoreOnly ? $scoreResultsLevel : $orphanLevel);
    if (!$hasNavigationIncoming && !$hasScoreIncoming) {
        $issues[] = ['type' => 'warning', 'node_id' => $nodeId, 'message' => 'Результат «' . $result['title'] . '» без входящих связей.'];
    }
    $nodes[] = array_merge($result, ['id' => $nodeId, 'entity_id' => $id, 'type' => 'result', 'answers_count' => 0, 'level' => $level, 'unreachable' => $unreachable, 'score_only' => $scoreOnly], $buildIncomingData($nodeId));
}
$issueNodeIds = [];
foreach ($issues as $issue) {
    if ($issue['node_id'] !== '') {
        $issueNodeIds[$issue['node_id']] = true;
    }
}
foreach ($nodes as &$node) {
    $node['has_issue'] = isset($issueNodeIds[$node['id']]);
}
unset($node);
usort($nodes, static fn (array $a, array $b): int => [$a['level'], $a['sort'], $a['entity_id']] <=> [$b['level'], $b['sort'], $b['entity_id']]);
$nodesById = [];
$columns = [];
foreach ($nodes as $node) {
    $nodesById[$node['id']] = $node;
    $columns[$node['level']][] = $node;
}
$edgeTypeLabels = [
    'start' => 'старт',
    'default_question' => 'переход по умолчанию',
    'default_result' => 'результат по умолчанию',
    'answer' => 'ответ',
    'result' => 'результат',
    'score' => 'баллы',
];

$editUrl = 'kk_quiz_quiz_edit.php?' . http_build_query(['ID' => $sectionId, 'lang' => $lang]);
$contentUrl = 'iblock_list_admin.php?' . http_build_query(['IBLOCK_ID' => $iblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'SECTION_ID' => $sectionId, 'find_section_section' => $sectionId, 'apply_filter' => 'Y', 'set_filter' => 'Y', 'lang' => $lang]);
$createBase = ['IBLOCK_ID' => $iblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'IBLOCK_SECTION_ID' => $sectionId, 'find_section_section' => $sectionId, 'from' => 'iblock_list_admin', 'lang' => $lang];
$createQuestionUrl = 'iblock_element_edit.php?' . http_build_query($createBase);
$createResultUrl = $createQuestionUrl;
$technicalUrl = 'iblock_section_edit.php?' . http_build_query(['IBLOCK_ID' => $iblockId, 'type' => Installer::IBLOCK_TYPE_ID, 'ID' => $sectionId, 'lang' => $lang]);
$statisticsUrl = 'kk_quiz_statistics.php?' . http_build_query(['quiz_code' => $quizCode, 'lang' => $lang]);
$schemaData = [
    'nodes' => $nodes,
    'edges' => $edges,
    'issues' => $issues,
    'edge_type_labels' => $edgeTypeLabels,
    'content_url' => $contentUrl,
    'settings_url' => $editUrl,
];

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
$context = new CAdminContextMenu([
    ['TEXT' => 'К списку квизов', 'LINK' => $listUrl, 'ICON' => 'btn_list'],
    ['TEXT' => 'Настройки квиза', 'LINK' => $editUrl],
    ['TEXT' => 'Вопросы и результаты', 'LINK' => $contentUrl],
    ['TEXT' => 'Создать вопрос', 'LINK' => $createQuestionUrl, 'ICON' => 'btn_new'],
    ['TEXT' => 'Создать результат', 'LINK' => $createResultUrl, 'ICON' => 'btn_new'],
    ['TEXT' => 'Стандартная форма раздела', 'LINK' => $technicalUrl],
    ['TEXT' => 'Статистика', 'LINK' => $statisticsUrl],
]);
$context->Show();
$escape = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
?>
<h2><?= $escape($section['NAME']) ?> — схема</h2>
<h3>Проверка структуры</h3>
<?php if ($issues === []): ?>
    <div class="adm-info-message adm-info-message-green">Проблем структуры не найдено.</div>
<?php else: ?>
    <ul class="kk-schema-issues"><?php foreach ($issues as $issue): ?><li class="kk-schema-issue--<?= $escape($issue['type']) ?>"><b><?= $issue['type'] === 'error' ? 'Ошибка' : 'Предупреждение' ?>:</b> <?= $escape($issue['message']) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<div class="kk-quiz-schema">
    <div class="kk-quiz-schema__toolbar">
        <input type="text" id="kk-quiz-schema-search" placeholder="Поиск по названию или коду">
        <select id="kk-quiz-schema-filter"><option value="">Все узлы</option><option value="question">Только вопросы</option><option value="result">Только результаты</option><option value="issue">Только с ошибками</option></select>
        <button type="button" class="adm-btn" id="kk-quiz-schema-fit">Показать всё</button>
    </div>
    <div class="kk-quiz-schema__layout">
        <div class="kk-quiz-schema__canvas-wrap" id="kk-quiz-schema-wrap">
            <svg class="kk-quiz-schema__edges" id="kk-quiz-schema-edges"><defs><marker id="kk-schema-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8 z" fill="#87919c"/></marker></defs></svg>
            <div class="kk-quiz-schema__canvas"><?php foreach ($columns as $level => $column): ?><div class="kk-schema-column"><div class="kk-schema-column__title"><?= $level === $scoreResultsLevel ? 'Результаты по баллам' : ($level === $orphanLevel ? 'Недостижимые / без связей' : 'Уровень ' . (int)$level) ?></div><?php foreach ($column as $node): ?><div id="kk-node-<?= $escape($node['id']) ?>" class="kk-quiz-schema-node kk-quiz-schema-node--<?= $escape($node['type']) ?><?= !$node['active'] ? ' is-inactive' : '' ?><?= $node['unreachable'] ? ' is-unreachable' : '' ?><?= $node['score_only'] ? ' is-score-only' : '' ?><?= $node['has_issue'] ? ' has-issue' : '' ?>" data-node-id="<?= $escape($node['id']) ?>" data-type="<?= $escape($node['type']) ?>" data-issue="<?= $node['has_issue'] ? 'Y' : 'N' ?>" data-search="<?= $escape(mb_strtolower($node['title'] . ' ' . $node['name'] . ' ' . $node['code'])) ?>"><div class="kk-quiz-schema-node__type"><?= $node['type'] === 'question' ? 'Вопрос' : ($node['type'] === 'result' ? 'Результат' : 'Старт') ?></div><div class="kk-quiz-schema-node__title"><?= $escape($node['title']) ?></div><?php if ($node['code'] !== ''): ?><div class="kk-quiz-schema-node__code"><?= $escape($node['code']) ?></div><?php endif; ?><?php if ($node['type'] === 'question'): ?><div class="kk-quiz-schema-node__meta">Ответов: <?= (int)$node['answers_count'] ?></div><?php endif; ?><?php if ($node['score_only']): ?><div class="kk-quiz-schema-node__meta">Используется в балльной логике</div><?php endif; ?><?php if ($node['incoming_preview'] !== ''): ?><div class="kk-quiz-schema-node__incoming" title="<?= $escape($node['incoming_title'] ?: $node['incoming_preview']) ?>"><?= $escape($node['incoming_preview']) ?></div><?php endif; ?><?php if ($node['edit_url'] !== ''): ?><a href="<?= $escape($node['edit_url']) ?>" class="adm-btn adm-btn-small">Редактировать</a><?php endif; ?></div><?php endforeach; ?></div><?php endforeach; ?></div>
        </div>
        <aside class="kk-quiz-schema__details" id="kk-quiz-schema-details"><div class="kk-quiz-schema__details-empty">Выберите вопрос или результат на схеме.</div></aside>
    </div>
</div>
<h3>Связи</h3>
<table class="adm-list-table"><thead><tr class="adm-list-table-header"><td class="adm-list-table-cell">Откуда</td><td class="adm-list-table-cell">Ответ / условие</td><td class="adm-list-table-cell">Куда</td><td class="adm-list-table-cell">Тип</td></tr></thead><tbody><?php foreach ($edges as $edge): ?><tr><td class="adm-list-table-cell"><?= $escape($nodesById[$edge['from']]['title'] ?? $edge['from']) ?></td><td class="adm-list-table-cell"><?= $escape($edge['label']) ?></td><td class="adm-list-table-cell"><?= $escape($nodesById[$edge['to']]['title'] ?? $edge['to']) ?><?= $edge['broken'] ? ' (не найдено)' : '' ?></td><td class="adm-list-table-cell"><?= $escape($edgeTypeLabels[$edge['type']] ?? $edge['type']) ?></td></tr><?php endforeach; ?><?php if ($edges === []): ?><tr><td colspan="4" class="adm-list-table-cell">Связей нет.</td></tr><?php endif; ?></tbody></table>
<style>
.kk-schema-issues{background:#fff;padding:14px 14px 14px 34px}.kk-schema-issue--error{color:#a11}.kk-schema-issue--warning{color:#8a5a00}.kk-quiz-schema__toolbar{display:flex;gap:10px;margin:15px 0}.kk-quiz-schema__layout{display:flex;gap:18px;align-items:flex-start}.kk-quiz-schema__canvas-wrap{flex:1 1 auto;min-width:0}.kk-quiz-schema__details{flex:0 0 360px;position:sticky;top:15px;max-height:calc(100vh - 120px);overflow:auto;background:#fff;border:1px solid #cdd2d7;border-radius:6px;padding:14px;box-sizing:border-box}.kk-quiz-schema__canvas-wrap{position:relative;overflow:auto;background:#f4f6f8;border:1px solid #cdd2d7;min-height:420px;padding:25px}.kk-quiz-schema__canvas{position:relative;display:flex;align-items:flex-start;gap:70px;min-width:max-content}.kk-schema-column{width:250px;display:flex;flex-direction:column;gap:18px}.kk-schema-column__title{font-weight:bold;color:#68717b}.kk-quiz-schema-node{position:relative;z-index:2;background:#fff;border:2px solid #7d9ec0;border-radius:6px;padding:12px;box-sizing:border-box}.kk-quiz-schema-node--start{border-color:#87919c;background:#eef1f4}.kk-quiz-schema-node--result{border-color:#56a06b;background:#f2fbf4}.kk-quiz-schema-node.is-score-only{border-color:#b78a20;border-style:dashed;background:#fffaf0}.kk-quiz-schema-node.has-issue{border-color:#d64b4b}.kk-quiz-schema-node.is-selected{box-shadow:0 0 0 3px rgba(49,99,160,.22)}.kk-quiz-schema-node.is-inactive{opacity:.55}.kk-quiz-schema-node.is-unreachable{border-style:dashed}.kk-quiz-schema-node__type{font-size:11px;text-transform:uppercase;color:#68717b}.kk-quiz-schema-node__title{font-weight:bold;margin:5px 0}.kk-quiz-schema-node__code,.kk-quiz-schema-node__meta{font-size:12px;color:#68717b;margin-bottom:7px}.kk-quiz-schema-node__incoming{margin:7px 0;padding:5px 7px;border-radius:4px;background:#eef5ff;color:#31506f;font-size:12px;line-height:1.35}.kk-quiz-schema-node.is-score-only .kk-quiz-schema-node__incoming{background:#fff3d7;color:#7a5600}.kk-quiz-schema__edges{position:absolute;inset:25px;width:calc(100% - 50px);height:calc(100% - 50px);overflow:visible;z-index:1;pointer-events:none}.kk-schema-edge{fill:none;stroke:#87919c;stroke-width:1.5}.kk-schema-edge.is-score{stroke:#b07800;stroke-dasharray:5 3}.kk-schema-edge-label{font-size:10px;fill:#505860}.kk-quiz-schema-detail__title{font-size:16px;font-weight:bold;margin-bottom:8px}.kk-quiz-schema-detail__meta{display:grid;grid-template-columns:auto 1fr;gap:4px 8px}.kk-quiz-schema-detail__section{margin-top:14px;padding-top:12px;border-top:1px solid #e1e5ea}.kk-quiz-schema-detail__section-title{font-weight:bold;margin-bottom:6px}.kk-quiz-schema-detail__list{margin:0;padding-left:19px}.kk-quiz-schema-detail-answer{background:#f6f8fa;border:1px solid #e1e5ea;border-radius:5px;padding:8px;margin-bottom:8px}.kk-quiz-schema-detail-answer__title{font-weight:bold}.kk-quiz-schema-detail-answer__meta{font-size:12px;color:#68717b;margin-top:3px}.kk-quiz-schema-detail__actions{display:flex;gap:7px;flex-wrap:wrap}.kk-quiz-schema__details-empty{color:#68717b}@media (max-width:1200px){.kk-quiz-schema__layout{flex-direction:column}.kk-quiz-schema__details{position:static;width:100%;max-height:none;flex-basis:auto}}.adm-list-table{width:100%;margin-top:10px}
</style>
<script>window.KKQuizSchemaData = <?= json_encode($schemaData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script>
<script>
(() => {
const data = window.KKQuizSchemaData || {nodes: [], edges: [], issues: []};
const wrap = document.getElementById('kk-quiz-schema-wrap');
const svg = document.getElementById('kk-quiz-schema-edges');
const search = document.getElementById('kk-quiz-schema-search');
const filter = document.getElementById('kk-quiz-schema-filter');
const details = document.getElementById('kk-quiz-schema-details');
const nodesById = new Map((data.nodes || []).map(node => [node.id, node]));
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const edgeLabel = edge => (data.edge_type_labels || {})[edge.type] || edge.type || '';
const nodeTitle = id => nodesById.get(id)?.title || id || '—';
const findEntity = (type, id, code) => (data.nodes || []).find(node => node.type === type && ((Number(id) > 0 && Number(node.entity_id) === Number(id)) || (code && node.code === code)));
const renderEdgeRow = (edge, direction) => {
    const otherId = direction === 'incoming' ? edge.from : edge.to;
    const connector = direction === 'incoming' ? 'из' : '→';
    return `<li><b>${escapeHtml(edgeLabel(edge))}</b>: ${escapeHtml(edge.label || '')} ${connector} ${escapeHtml(nodeTitle(otherId))}</li>`;
};
const renderIncomingRow = edge => {
    const source = escapeHtml(nodeTitle(edge.from));
    const label = escapeHtml(edge.label || '');
    if (edge.type === 'start') return '<li>Стартовый вопрос</li>';
    if (edge.type === 'default_question') return `<li>По умолчанию из «${source}»</li>`;
    if (edge.type === 'default_result') return `<li>Результат по умолчанию из «${source}»</li>`;
    if (edge.type === 'score') return `<li>Баллы: «${label}» из «${source}»</li>`;
    return `<li>При ответе: «${label}» из «${source}»</li>`;
};
const section = (title, body) => `<div class="kk-quiz-schema-detail__section"><div class="kk-quiz-schema-detail__section-title">${escapeHtml(title)}</div>${body}</div>`;
const renderAnswers = node => {
    const answers = node.answers_for_panel || [];
    if (!answers.length) return '<div>Ответов нет.</div>';
    return answers.map(answer => {
        const targets = [];
        const next = findEntity('question', answer.next_question_id, answer.next_question_code);
        const result = findEntity('result', answer.result_id, answer.result_code);
        const scoreResult = findEntity('result', answer.score_result_id, answer.score_result_code);
        if (answer.has_navigation_target) {
            if (next) targets.push(`→ Вопрос: ${escapeHtml(next.title)}`);
            if (result) targets.push(`→ Результат: ${escapeHtml(result.title)}`);
            if (!next && !result) targets.push('Переход: цель не найдена');
        }
        if (answer.has_score_target) targets.push(`Баллы: ${Number(answer.score_value) || 0} → результат ${escapeHtml(scoreResult?.title || answer.score_result_code || answer.score_result_id || 'не найден')}`);
        else if (Number(answer.score_value) !== 0) targets.push(`Баллы: ${Number(answer.score_value)}`);
        if (!answer.has_navigation_target && !answer.has_score_target) {
            targets.push('Без собственного перехода');
            if (Number(node.default_next_question_id) > 0 || Number(node.default_result_id) > 0) targets.push('Используется переход по умолчанию');
        }
        return `<div class="kk-quiz-schema-detail-answer"><div class="kk-quiz-schema-detail-answer__title">Ответ: ${escapeHtml(answer.text || 'Без текста')}</div>${answer.code ? `<div class="kk-quiz-schema-detail-answer__meta">Код: ${escapeHtml(answer.code)}</div>` : ''}<div class="kk-quiz-schema-detail-answer__meta">${answer.active ? 'Активен' : 'Неактивен'} · сортировка ${Number(answer.sort) || 0}</div><div>${targets.join('<br>')}</div></div>`;
    }).join('');
};
const renderDefault = node => {
    const next = findEntity('question', node.default_next_question_id, '');
    const result = findEntity('result', node.default_result_id, '');
    if (!next && !result) return '';
    return section('Переход по умолчанию', `<div>${next ? `Вопрос: ${escapeHtml(next.title)}` : `Результат: ${escapeHtml(result.title)}`}</div>`);
};
const renderResultMeta = node => {
    const meta = node.result_meta_for_panel || {};
    const labels = {cta_text:'CTA текст',cta_url:'CTA ссылка',cta_target:'CTA target',show_form:'Форма включена',video_url:'Видео'};
    const rows = Object.entries(labels).filter(([key]) => meta[key] !== undefined && meta[key] !== '').map(([key,label]) => `<div><b>${escapeHtml(label)}:</b> ${escapeHtml(meta[key])}</div>`);
    return rows.length ? section('Данные результата', rows.join('')) : '';
};
const renderDetails = nodeId => {
    const node = nodesById.get(nodeId);
    if (!node) return;
    const incoming = (data.edges || []).filter(edge => !edge.broken && edge.to === node.id);
    const outgoing = (data.edges || []).filter(edge => !edge.broken && edge.from === node.id);
    const nodeIssues = (data.issues || []).filter(issue => issue.node_id === node.id);
    const typeTitle = node.type === 'question' ? 'Вопрос' : (node.type === 'result' ? 'Результат' : 'Старт');
    const statuses = [node.active ? 'активный' : 'неактивный'];
    if (node.unreachable) statuses.push('недостижимый');
    if (node.has_issue) statuses.push('есть предупреждения/ошибки');
    let html = `<div class="kk-quiz-schema-detail__title">${typeTitle}</div><div class="kk-quiz-schema-detail__meta"><b>Название</b><span>${escapeHtml(node.title || node.name || '—')}</span>`;
    if (node.type !== 'start') html += `<b>Код</b><span>${escapeHtml(node.code || '—')}</span><b>ID</b><span>${Number(node.entity_id) || 0}</span><b>Активность</b><span>${node.active ? 'Да' : 'Нет'}</span><b>Уровень</b><span>${Number(node.level) || 0}</span><b>Статус</b><span>${escapeHtml(statuses.join(', '))}</span>`;
    if (node.type === 'question') html += `<b>Тип вопроса</b><span>${escapeHtml(node.question_type || '—')}</span><b>Ответов</b><span>${Number(node.answers_count) || 0}</span>`;
    if (node.type === 'result') html += `<b>Только баллы</b><span>${node.score_only ? 'Да' : 'Нет'}</span><b>Недостижимый</b><span>${node.unreachable ? 'Да' : 'Нет'}</span>`;
    html += '</div>';
    if (node.type === 'start') html += '<div>Стартовая точка квиза.</div>';
    html += section('Входящие условия', incoming.length ? `<ul class="kk-quiz-schema-detail__list">${incoming.map(renderIncomingRow).join('')}</ul>` : '<div>Входящих условий нет.</div>');
    if (node.type === 'question') html += section('Ответы', renderAnswers(node)) + renderDefault(node);
    if (node.type === 'result') html += renderResultMeta(node);
    html += section('Исходящие связи', outgoing.length ? `<ul class="kk-quiz-schema-detail__list">${outgoing.map(edge => renderEdgeRow(edge, 'outgoing')).join('')}</ul>` : '<div>Исходящих связей нет.</div>');
    html += section('Проблемы', nodeIssues.length ? `<ul class="kk-quiz-schema-detail__list">${nodeIssues.map(issue => `<li><b>${escapeHtml(issue.type === 'error' ? 'Ошибка' : 'Предупреждение')}:</b> ${escapeHtml(issue.message || '')}</li>`).join('')}</ul>` : '<div>Проблем по узлу нет.</div>');
    const actions = [];
    const safeHref = String(node.edit_url || '');
    if (safeHref) actions.push(`<a class="adm-btn adm-btn-save" href="${escapeHtml(safeHref)}">Редактировать ${node.type === 'question' ? 'вопрос' : 'результат'}</a>`);
    if (node.type === 'start') actions.push(`<a class="adm-btn adm-btn-save" href="${escapeHtml(data.settings_url || '')}">Настройки квиза</a>`);
    else if (data.content_url) actions.push(`<a class="adm-btn" href="${escapeHtml(data.content_url)}">Открыть вопросы и результаты</a>`);
    if (actions.length) html += section('Действия', `<div class="kk-quiz-schema-detail__actions">${actions.join('')}</div>`);
    details.innerHTML = html;
};
const draw = () => {
    svg.querySelectorAll('.kk-schema-edge,.kk-schema-edge-label').forEach(el => el.remove());
    const box = svg.getBoundingClientRect();
    (data.edges || []).filter(edge => !edge.broken).forEach(edge => {
        const from = document.getElementById('kk-node-' + edge.from);
        const to = document.getElementById('kk-node-' + edge.to);
        if (!from || !to || from.hidden || to.hidden) return;
        const a = from.getBoundingClientRect(), b = to.getBoundingClientRect();
        const x1 = a.right - box.left, y1 = a.top + a.height / 2 - box.top, x2 = b.left - box.left, y2 = b.top + b.height / 2 - box.top;
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', `M${x1},${y1} C${x1+35},${y1} ${x2-35},${y2} ${x2},${y2}`);
        path.setAttribute('class', 'kk-schema-edge' + (edge.type === 'score' ? ' is-score' : ''));
        path.setAttribute('marker-end', 'url(#kk-schema-arrow)');
        const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
        title.textContent = edge.label;
        path.appendChild(title); svg.appendChild(path);
    });
};
const apply = () => {
    const term = (search.value || '').trim().toLocaleLowerCase();
    document.querySelectorAll('.kk-quiz-schema-node').forEach(node => {
        const type = filter.value;
        node.hidden = (!!term && !node.dataset.search.includes(term)) || (type === 'issue' && node.dataset.issue !== 'Y') || (type && type !== 'issue' && node.dataset.type !== type);
    });
    requestAnimationFrame(draw);
};
document.querySelectorAll('.kk-quiz-schema-node').forEach(nodeEl => nodeEl.addEventListener('click', event => {
    if (event.target.closest('a')) return;
    document.querySelectorAll('.kk-quiz-schema-node.is-selected').forEach(el => el.classList.remove('is-selected'));
    nodeEl.classList.add('is-selected');
    renderDetails(nodeEl.dataset.nodeId);
}));
search.addEventListener('input', apply); filter.addEventListener('change', apply);
document.getElementById('kk-quiz-schema-fit').addEventListener('click', () => {search.value='';filter.value='';apply();wrap.scrollTo({left:0,top:0,behavior:'smooth'});});
window.addEventListener('resize', draw); wrap.addEventListener('scroll', draw); requestAnimationFrame(draw);
})();
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>
