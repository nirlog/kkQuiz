<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Kk\Quiz\Analytics\QuizEventTable;

final class QuizEventService
{
    public const EVENT_QUIZ_VIEW = 'quiz_view';
    public const EVENT_QUIZ_OPEN = 'quiz_open';
    public const EVENT_QUIZ_START = 'quiz_start';
    public const EVENT_QUESTION_ANSWER = 'question_answer';
    public const EVENT_RESULT_SHOW = 'result_show';
    public const EVENT_FORM_SHOW = 'form_show';
    public const EVENT_LEAD_SUCCESS = 'lead_success';

    private const DEDUPE_EVENTS = [
        self::EVENT_QUIZ_VIEW,
        self::EVENT_QUIZ_OPEN,
        self::EVENT_QUIZ_START,
        self::EVENT_RESULT_SHOW,
        self::EVENT_FORM_SHOW,
        self::EVENT_LEAD_SUCCESS,
    ];

    public function track(array $payload, array $server = []): array
    {
        $eventType = $this->normalizeString($payload['event_type'] ?? '', 50);
        if (!in_array($eventType, $this->getAllowedEventTypes(), true)) {
            return [
                'success' => false,
                'errors' => ['UNKNOWN_EVENT_TYPE'],
            ];
        }

        $quizCode = $this->normalizeString($payload['quiz_code'] ?? '', 100);
        if ($quizCode === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $quizCode)) {
            return [
                'success' => false,
                'errors' => ['INVALID_QUIZ_CODE'],
            ];
        }

        $runId = $this->normalizeString($payload['run_id'] ?? '', 64);
        if ($runId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $runId)) {
            return [
                'success' => false,
                'errors' => ['INVALID_RUN_ID'],
            ];
        }

        $utm = is_array($payload['utm'] ?? null) ? $payload['utm'] : [];
        $sessionId = $this->normalizeString($payload['session_id'] ?? '', 128);
        if ($sessionId === '' && session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = $this->normalizeString((string)session_id(), 128);
        }

        $row = [
            'QUIZ_CODE' => $quizCode,
            'QUIZ_SECTION_ID' => $this->toNullableInt($payload['quiz_section_id'] ?? null),
            'SESSION_ID' => $sessionId,
            'RUN_ID' => $runId,
            'EVENT_TYPE' => $eventType,
            'QUESTION_ID' => $this->toNullableInt($payload['question_id'] ?? null),
            'QUESTION_CODE' => $this->normalizeString($payload['question_code'] ?? '', 100),
            'ANSWER_CODE' => $this->normalizeString($payload['answer_code'] ?? '', 100),
            'RESULT_ID' => $this->toNullableInt($payload['result_id'] ?? null),
            'RESULT_CODE' => $this->normalizeString($payload['result_code'] ?? '', 100),
            'PAGE_URL' => $this->normalizeString($payload['page_url'] ?? '', 500),
            'REFERER' => $this->normalizeString($payload['referer'] ?? '', 500),
            'UTM_SOURCE' => $this->normalizeString($utm['utm_source'] ?? $payload['utm_source'] ?? '', 100),
            'UTM_MEDIUM' => $this->normalizeString($utm['utm_medium'] ?? $payload['utm_medium'] ?? '', 100),
            'UTM_CAMPAIGN' => $this->normalizeString($utm['utm_campaign'] ?? $payload['utm_campaign'] ?? '', 150),
            'UTM_CONTENT' => $this->normalizeString($utm['utm_content'] ?? $payload['utm_content'] ?? '', 150),
            'UTM_TERM' => $this->normalizeString($utm['utm_term'] ?? $payload['utm_term'] ?? '', 150),
            'USER_AGENT' => $this->normalizeString($server['HTTP_USER_AGENT'] ?? $payload['user_agent'] ?? '', 500),
            'IP_HASH' => $this->hashIp((string)($server['REMOTE_ADDR'] ?? '')),
        ];

        try {
            if ($this->isDuplicate($row)) {
                return ['success' => true, 'duplicate' => true];
            }

            $result = QuizEventTable::add($row);
            if (!$result->isSuccess()) {
                return [
                    'success' => false,
                    'errors' => ['TRACK_FAILED'],
                ];
            }
        } catch (\Throwable) {
            return [
                'success' => false,
                'errors' => ['TRACK_FAILED'],
            ];
        }

        return ['success' => true];
    }

    private function getAllowedEventTypes(): array
    {
        return [
            self::EVENT_QUIZ_VIEW,
            self::EVENT_QUIZ_OPEN,
            self::EVENT_QUIZ_START,
            self::EVENT_QUESTION_ANSWER,
            self::EVENT_RESULT_SHOW,
            self::EVENT_FORM_SHOW,
            self::EVENT_LEAD_SUCCESS,
        ];
    }

    private function isDuplicate(array $row): bool
    {
        if (!in_array((string)$row['EVENT_TYPE'], self::DEDUPE_EVENTS, true)) {
            return false;
        }

        $filter = [
            '=RUN_ID' => (string)$row['RUN_ID'],
            '=EVENT_TYPE' => (string)$row['EVENT_TYPE'],
        ];

        if ((string)$row['EVENT_TYPE'] === self::EVENT_RESULT_SHOW && (string)$row['RESULT_CODE'] !== '') {
            $filter['=RESULT_CODE'] = (string)$row['RESULT_CODE'];
        }

        $existing = QuizEventTable::getList([
            'select' => ['ID'],
            'filter' => $filter,
            'limit' => 1,
        ])->fetch();

        return is_array($existing);
    }

    private function normalizeString(mixed $value, int $maxLength): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return (string)mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private function hashIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }

        return hash('sha256', $ip . '|kk.quiz');
    }
}
