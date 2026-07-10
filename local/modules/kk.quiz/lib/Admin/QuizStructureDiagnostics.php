<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

final class QuizStructureDiagnostics
{
    public static function build(int $iblockId, int $sectionId): array
    {
        if ($iblockId <= 0 || $sectionId <= 0) {
            return self::emptyResult();
        }

        $items = self::loadItems($iblockId, $sectionId);
        $questions = [];
        $results = [];

        foreach ($items as $item) {
            if ($item['entity_type'] === 'QUESTION') {
                $questions[(int)$item['id']] = $item;
            }
            if ($item['entity_type'] === 'RESULT') {
                $results[(int)$item['id']] = $item;
            }
        }

        $messages = [];
        $questionsCount = count($questions);
        $resultsCount = count($results);
        $startQuestion = $questions === [] ? null : reset($questions);

        $messages[] = ['type' => $questionsCount > 0 ? 'success' : 'error', 'message' => $questionsCount > 0 ? 'Вопросов: ' . $questionsCount : 'Ошибка: в квизе нет активных вопросов.'];
        $messages[] = ['type' => $resultsCount > 0 ? 'success' : 'error', 'message' => $resultsCount > 0 ? 'Результатов: ' . $resultsCount : 'Ошибка: в квизе нет активных результатов.'];

        if (is_array($startQuestion)) {
            $messages[] = ['type' => 'success', 'message' => 'Стартовый вопрос: ' . $startQuestion['title']];
        }

        $questionEdges = [];
        $usedResultIds = [];
        $graphEdges = [];

        foreach ($questions as $questionId => $question) {
            $transitionQuestionIds = [];
            $transitionResultIds = [];
            $hasTransition = false;

            if ($question['answers_invalid'] === true) {
                $messages[] = ['type' => 'warning', 'message' => 'Предупреждение: у вопроса “' . $question['title'] . '” повреждены данные ответов.'];
            }

            foreach ($question['answers'] as $answer) {
                $label = self::getAnswerLabel($answer);
                foreach (['next_question_id'] as $field) {
                    $id = self::toPositiveInt($answer[$field] ?? null);
                    if ($id > 0) {
                        $hasTransition = true;
                        $transitionQuestionIds[] = $id;
                        $graphEdges[] = self::buildEdge($questionId, $id, 'question', $label, 'answer', $questions, $results);
                    }
                }

                foreach (['result_id', 'score_result_id'] as $field) {
                    $id = self::toPositiveInt($answer[$field] ?? null);
                    if ($id > 0) {
                        $hasTransition = true;
                        $transitionResultIds[] = $id;
                        $graphEdges[] = self::buildEdge($questionId, $id, 'result', $label, 'answer_result', $questions, $results);
                    }
                }
            }

            if ($question['default_next_question_id'] > 0) {
                $hasTransition = true;
                $transitionQuestionIds[] = $question['default_next_question_id'];
                $graphEdges[] = self::buildEdge($questionId, $question['default_next_question_id'], 'question', 'По умолчанию', 'default_next', $questions, $results);
            }

            if ($question['default_result_id'] > 0) {
                $hasTransition = true;
                $transitionResultIds[] = $question['default_result_id'];
                $graphEdges[] = self::buildEdge($questionId, $question['default_result_id'], 'result', 'Финальный результат по умолчанию', 'default_result', $questions, $results);
            }

            if (!$hasTransition) {
                $messages[] = ['type' => 'warning', 'message' => 'Предупреждение: у вопроса “' . $question['title'] . '” не настроены переходы.'];
            }

            $questionEdges[$questionId] = [];
            foreach (array_values(array_unique($transitionQuestionIds)) as $targetQuestionId) {
                if (!isset($questions[$targetQuestionId])) {
                    $messages[] = ['type' => 'warning', 'message' => 'Предупреждение: вопрос “' . $question['title'] . '” ведёт на несуществующий или неактивный вопрос ID ' . $targetQuestionId . '.'];
                    continue;
                }

                $questionEdges[$questionId][] = $targetQuestionId;
            }

            foreach (array_values(array_unique($transitionResultIds)) as $targetResultId) {
                if (!isset($results[$targetResultId])) {
                    $messages[] = ['type' => 'warning', 'message' => 'Предупреждение: вопрос “' . $question['title'] . '” ведёт на несуществующий или неактивный результат ID ' . $targetResultId . '.'];
                    continue;
                }

                $usedResultIds[$targetResultId] = true;
            }
        }

        $reachableQuestionIds = [];
        if (is_array($startQuestion)) {
            $reachableQuestionIds = self::collectReachableQuestionIds((int)$startQuestion['id'], $questionEdges);
            foreach ($questions as $questionId => $question) {
                if (!isset($reachableQuestionIds[$questionId])) {
                    $messages[] = ['type' => 'warning', 'message' => 'Предупреждение: вопрос “' . $question['title'] . '” недостижим из стартового вопроса.'];
                }
            }
        }

        foreach ($results as $resultId => $result) {
            if (!isset($usedResultIds[$resultId])) {
                $messages[] = ['type' => 'warning', 'message' => 'Предупреждение: результат “' . $result['title'] . '” недостижим.'];
            }
        }

        return [
            'summary' => [
                'questions_count' => $questionsCount,
                'results_count' => $resultsCount,
                'start_question_title' => is_array($startQuestion) ? $startQuestion['title'] : '',
            ],
            'items' => $messages,
            'graph' => self::buildGraph($questions, $results, $graphEdges, is_array($startQuestion) ? (int)$startQuestion['id'] : 0, $reachableQuestionIds, $usedResultIds),
        ];
    }

    private static function emptyResult(): array
    {
        return [
            'summary' => [
                'questions_count' => 0,
                'results_count' => 0,
                'start_question_title' => '',
            ],
            'items' => [],
            'graph' => [
                'nodes' => [],
                'edges' => [],
            ],
        ];
    }

    private static function loadItems(int $iblockId, int $sectionId): array
    {
        $items = [];
        $elements = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
                'SECTION_ID' => $sectionId,
                'ACTIVE' => 'Y',
                'INCLUDE_SUBSECTIONS' => 'N',
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'SORT']
        );

        while ($elementObject = $elements->GetNextElement()) {
            $fields = $elementObject->GetFields();
            $properties = $elementObject->GetProperties();
            $entityType = strtoupper(self::getPropertyEnumXmlId($properties, 'KK_ENTITY_TYPE'));
            if ($entityType === '') {
                $entityType = strtoupper((string)self::getPropertyValue($properties, 'KK_ENTITY_TYPE'));
            }

            if (!in_array($entityType, ['QUESTION', 'RESULT'], true)) {
                continue;
            }

            $adminName = (string)($fields['NAME'] ?? '');
            $publicTitle = trim((string)self::getPropertyValue($properties, 'KK_PUBLIC_TITLE'));
            $answersData = self::decodeAnswers(self::getPropertyRawValue($properties, 'KK_ANSWERS'));

            $items[] = [
                'id' => (int)$fields['ID'],
                'title' => $adminName !== '' ? $adminName : ($publicTitle !== '' ? $publicTitle : 'ID ' . (int)$fields['ID']),
                'sort' => (int)($fields['SORT'] ?? 0),
                'entity_type' => $entityType,
                'answers' => $answersData['answers'],
                'answers_invalid' => $answersData['invalid'],
                'default_next_question_id' => self::toPositiveInt(self::getPropertyValue($properties, 'KK_DEFAULT_NEXT_QUESTION')),
                'default_result_id' => self::toPositiveInt(self::getPropertyValue($properties, 'KK_DEFAULT_RESULT')),
            ];
        }

        return $items;
    }


    private static function buildGraph(
        array $questions,
        array $results,
        array $edges,
        int $startQuestionId,
        array $reachableQuestionIds,
        array $usedResultIds
    ): array {
        $nodes = [];

        foreach ($questions as $questionId => $question) {
            $nodes[] = [
                'id' => $questionId,
                'type' => 'question',
                'title' => $question['title'],
                'sort' => (int)($question['sort'] ?? 0),
                'is_start' => $questionId === $startQuestionId,
                'is_reachable' => isset($reachableQuestionIds[$questionId]),
            ];
        }

        foreach ($results as $resultId => $result) {
            $nodes[] = [
                'id' => $resultId,
                'type' => 'result',
                'title' => $result['title'],
                'sort' => (int)($result['sort'] ?? 0),
                'is_start' => false,
                'is_reachable' => isset($usedResultIds[$resultId]),
            ];
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private static function buildEdge(
        int $fromQuestionId,
        int $targetId,
        string $targetType,
        string $label,
        string $kind,
        array $questions,
        array $results
    ): array {
        $targetMap = $targetType === 'question' ? $questions : $results;
        $isBroken = !isset($targetMap[$targetId]);

        return [
            'from' => $fromQuestionId,
            'to' => $targetId,
            'to_type' => $targetType,
            'to_title' => $isBroken ? 'ID ' . $targetId . ' не найден' : (string)$targetMap[$targetId]['title'],
            'label' => $label !== '' ? $label : 'Ответ',
            'kind' => $kind,
            'is_broken' => $isBroken,
        ];
    }

    private static function getAnswerLabel(array $answer): string
    {
        $text = self::cleanString($answer['text'] ?? $answer['TEXT'] ?? '');

        return $text !== '' ? $text : 'Ответ';
    }

    private static function decodeAnswers(mixed $value): array
    {
        if (is_string($value)) {
            $raw = trim($value);
            if ($raw === '') {
                return ['answers' => [], 'invalid' => false];
            }

            $decoded = json_decode($raw, true);
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
            return ['answers' => [], 'invalid' => false];
        }

        if (
            isset($rows['text'])
            || isset($rows['TEXT'])
            || isset($rows['next_question_id'])
            || isset($rows['NEXT_QUESTION_ID'])
            || isset($rows['result_id'])
            || isset($rows['RESULT_ID'])
            || isset($rows['score_result_id'])
            || isset($rows['SCORE_RESULT_ID'])
        ) {
            $rows = [$rows];
        }

        $answers = [];
        foreach ($rows as $answer) {
            if (is_string($answer)) {
                $decoded = json_decode(trim($answer), true);
                $answer = is_array($decoded) ? $decoded : null;
            }

            if (!is_array($answer)) {
                continue;
            }

            $answers[] = [
                'text' => self::cleanString($answer['text'] ?? $answer['TEXT'] ?? ''),
                'next_question_id' => self::toPositiveInt($answer['next_question_id'] ?? $answer['NEXT_QUESTION_ID'] ?? null),
                'result_id' => self::toPositiveInt($answer['result_id'] ?? $answer['RESULT_ID'] ?? null),
                'score_result_id' => self::toPositiveInt($answer['score_result_id'] ?? $answer['SCORE_RESULT_ID'] ?? null),
            ];
        }

        return ['answers' => $answers, 'invalid' => false];
    }

    private static function cleanString(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? trim(strip_tags((string)$value)) : '';
    }


    private static function collectReachableQuestionIds(int $startQuestionId, array $edges): array
    {
        $reachable = [];
        $queue = [$startQuestionId];

        while ($queue !== []) {
            $questionId = (int)array_shift($queue);
            if ($questionId <= 0 || isset($reachable[$questionId])) {
                continue;
            }

            $reachable[$questionId] = true;
            foreach ((array)($edges[$questionId] ?? []) as $nextQuestionId) {
                if (!isset($reachable[$nextQuestionId])) {
                    $queue[] = (int)$nextQuestionId;
                }
            }
        }

        return $reachable;
    }


    private static function getPropertyRawValue(array $properties, string $code): mixed
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return null;
        }

        return $properties[$code]['VALUE'] ?? null;
    }

    private static function getPropertyValue(array $properties, string $code): mixed
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return null;
        }

        $value = $properties[$code]['VALUE'] ?? null;
        if (is_array($value)) {
            $value = reset($value);
        }

        return $value;
    }

    private static function getPropertyEnumXmlId(array $properties, string $code): string
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return '';
        }

        $xmlId = $properties[$code]['VALUE_XML_ID'] ?? '';
        if (is_array($xmlId)) {
            $xmlId = reset($xmlId);
        }

        if (is_string($xmlId) && $xmlId !== '') {
            return $xmlId;
        }

        $value = $properties[$code]['VALUE'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private static function toPositiveInt(mixed $value): int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        $value = (int)$value;

        return $value > 0 ? $value : 0;
    }
}
