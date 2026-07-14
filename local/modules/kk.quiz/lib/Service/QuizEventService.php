<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Kk\Quiz\Analytics\QuizEventTable;

final class QuizEventService
{
    public const EVENT_QUIZ_VIEW = 'quiz_view';
    public const EVENT_QUIZ_OPEN = 'quiz_open';
    public const EVENT_QUIZ_START = 'quiz_start';
    public const EVENT_QUESTION_SHOW = 'question_show';
    public const EVENT_QUESTION_ANSWER = 'question_answer';
    public const EVENT_RESULT_SHOW = 'result_show';
    public const EVENT_FORM_SHOW = 'form_show';
    public const EVENT_LEAD_SUCCESS = 'lead_success';

    private const RATE_LIMIT_MAX_EVENTS = 120;
    private const RATE_LIMIT_WINDOW_SECONDS = 600;

    private const DEDUPE_EVENTS = [
        self::EVENT_QUIZ_VIEW,
        self::EVENT_QUIZ_OPEN,
        self::EVENT_QUIZ_START,
        self::EVENT_QUESTION_SHOW,
        self::EVENT_QUESTION_ANSWER,
        self::EVENT_RESULT_SHOW,
        self::EVENT_FORM_SHOW,
        self::EVENT_LEAD_SUCCESS,
    ];

    public function track(array $payload): array
    {
        if ($this->isAdminUser()) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'ADMIN_USER',
            ];
        }

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

        if (!$this->checkRateLimit($quizCode)) {
            return [
                'success' => false,
                'errors' => ['RATE_LIMIT'],
            ];
        }

        $runId = $this->normalizeString($payload['run_id'] ?? '', 64);
        if ($runId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $runId)) {
            return [
                'success' => false,
                'errors' => ['INVALID_RUN_ID'],
            ];
        }

        $row = [
            'QUIZ_CODE' => $quizCode,
            'QUIZ_SECTION_ID' => $this->toNullableInt($payload['quiz_section_id'] ?? null),
            'RUN_ID' => $runId,
            'EVENT_TYPE' => $eventType,
            'STEP_INDEX' => $this->toNullableInt($payload['step_index'] ?? null),
            'QUESTION_ID' => $this->toNullableInt($payload['question_id'] ?? null),
            'QUESTION_CODE' => $this->normalizeString($payload['question_code'] ?? '', 100),
            'ANSWER_CODE' => $this->normalizeString($payload['answer_code'] ?? '', 100),
            'RESULT_ID' => $this->toNullableInt($payload['result_id'] ?? null),
            'RESULT_CODE' => $this->normalizeString($payload['result_code'] ?? '', 100),
            'LEAD_ID' => $this->toNullableInt($payload['lead_id'] ?? null),
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

    private function isAdminUser(): bool
    {
        global $USER;

        return is_object($USER)
            && method_exists($USER, 'IsAuthorized')
            && method_exists($USER, 'IsAdmin')
            && $USER->IsAuthorized()
            && $USER->IsAdmin();
    }

    private function checkRateLimit(string $quizCode): bool
    {
        try {
            if (!class_exists('CPHPCache')) {
                return true;
            }

            $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
            if ($ip === '') {
                $ip = 'unknown';
            }

            $cacheId = 'kk_quiz_track_' . sha1($ip . '|' . $quizCode);
            $cacheDir = '/kk.quiz/track-rate-limit';
            $now = time();
            $payload = [
                'count' => 0,
                'started_at' => $now,
            ];

            $cache = new \CPHPCache();
            if ($cache->InitCache(self::RATE_LIMIT_WINDOW_SECONDS, $cacheId, $cacheDir)) {
                $cachedPayload = $cache->GetVars();
                if (is_array($cachedPayload)) {
                    $payload = $cachedPayload;
                }
            }

            $startedAt = (int)($payload['started_at'] ?? $now);
            $count = (int)($payload['count'] ?? 0);

            if ($startedAt <= 0 || $startedAt + self::RATE_LIMIT_WINDOW_SECONDS <= $now) {
                $startedAt = $now;
                $count = 0;
            }

            if ($count >= self::RATE_LIMIT_MAX_EVENTS) {
                return false;
            }

            $cache = new \CPHPCache();
            $cache->Clean($cacheId, $cacheDir);
            if ($cache->StartDataCache(self::RATE_LIMIT_WINDOW_SECONDS, $cacheId, $cacheDir)) {
                $cache->EndDataCache([
                    'count' => $count + 1,
                    'started_at' => $startedAt,
                ]);
            }
        } catch (\Throwable) {
            return true;
        }

        return true;
    }

    private function getAllowedEventTypes(): array
    {
        return [
            self::EVENT_QUIZ_VIEW,
            self::EVENT_QUIZ_OPEN,
            self::EVENT_QUIZ_START,
            self::EVENT_QUESTION_SHOW,
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

        $eventType = (string)$row['EVENT_TYPE'];
        if (in_array($eventType, [self::EVENT_QUESTION_SHOW, self::EVENT_QUESTION_ANSWER], true)) {
            $filter['=QUESTION_CODE'] = (string)$row['QUESTION_CODE'];
            if ($row['STEP_INDEX'] !== null) {
                $filter['=STEP_INDEX'] = (int)$row['STEP_INDEX'];
            }
        }

        if ($eventType === self::EVENT_QUESTION_ANSWER) {
            $filter['=ANSWER_CODE'] = (string)$row['ANSWER_CODE'];
        }

        if ($eventType === self::EVENT_RESULT_SHOW && (string)$row['RESULT_CODE'] !== '') {
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
}
