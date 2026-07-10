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

        foreach ($questions as $questionId => $question) {
            $transitionQuestionIds = [];
            $transitionResultIds = [];
            $hasTransition = false;

            if ($question['answers_invalid'] === true) {
                $messages[] = ['type' => 'warning', 'message' => 'Предупреждение: у вопроса “' . $question['title'] . '” повреждены данные ответов.'];
            }

            foreach ($question['answers'] as $answer) {
                foreach (['next_question_id'] as $field) {
                    $id = self::toPositiveInt($answer[$field] ?? null);
                    if ($id > 0) {
                        $hasTransition = true;
                        $transitionQuestionIds[] = $id;
                    }
                }

                foreach (['result_id', 'score_result_id'] as $field) {
                    $id = self::toPositiveInt($answer[$field] ?? null);
                    if ($id > 0) {
                        $hasTransition = true;
                        $transitionResultIds[] = $id;
                    }
                }
            }

            if ($question['default_next_question_id'] > 0) {
                $hasTransition = true;
                $transitionQuestionIds[] = $question['default_next_question_id'];
            }

            if ($question['default_result_id'] > 0) {
                $hasTransition = true;
                $transitionResultIds[] = $question['default_result_id'];
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
            $answersData = self::decodeAnswers(self::getPropertyValue($properties, 'KK_ANSWERS'));

            $items[] = [
                'id' => (int)$fields['ID'],
                'title' => $adminName !== '' ? $adminName : ($publicTitle !== '' ? $publicTitle : 'ID ' . (int)$fields['ID']),
                'entity_type' => $entityType,
                'answers' => $answersData['answers'],
                'answers_invalid' => $answersData['invalid'],
                'default_next_question_id' => self::toPositiveInt(self::getPropertyValue($properties, 'KK_DEFAULT_NEXT_QUESTION')),
                'default_result_id' => self::toPositiveInt(self::getPropertyValue($properties, 'KK_DEFAULT_RESULT')),
            ];
        }

        return $items;
    }

    private static function decodeAnswers(mixed $value): array
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            return ['answers' => [], 'invalid' => false];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['answers' => [], 'invalid' => true];
        }

        $answers = [];
        foreach ($decoded as $answer) {
            if (is_array($answer)) {
                $answers[] = $answer;
            }
        }

        return ['answers' => $answers, 'invalid' => false];
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
