<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Json;
use Kk\Quiz\Repository\LeadRepository;

final class LeadService
{
    private const FIELD_LABELS = [
        'name' => 'имя',
        'phone' => 'телефон',
        'email' => 'email',
        'messenger' => 'мессенджер',
        'comment' => 'комментарий',
    ];
    private const RATE_LIMIT_TTL = 60;
    private const RATE_LIMIT_MAX = 3;

    private QuizService $quizService;
    private LeadRepository $leadRepository;

    public function __construct(?QuizService $quizService = null, ?LeadRepository $leadRepository = null)
    {
        $this->quizService = $quizService ?? new QuizService();
        $this->leadRepository = $leadRepository ?? new LeadRepository();
    }

    public function submit(array $payload): array
    {
        $errors = [];
        if ($this->cleanString($payload['website'] ?? $payload['honeypot'] ?? '') !== '') {
            return ['success' => false, 'errors' => ['Заявка отклонена']];
        }
        if (!$this->checkRateLimit()) {
            return ['success' => false, 'errors' => ['Слишком частая отправка формы. Попробуйте позже.']];
        }

        $quizCode = $this->cleanCode($payload['quiz_code'] ?? '');
        if ($quizCode === '') {
            $errors[] = 'Не указан код квиза';
        }
        $quiz = $quizCode !== '' ? $this->quizService->getPublicQuiz($quizCode) : null;
        if ($quiz === null) {
            $errors[] = 'Квиз не найден или неактивен';
        }

        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $cleanFields = $this->cleanFields($fields);
        if ($quiz !== null) {
            $errors = array_merge($errors, $this->validateRequiredFields($quiz, $cleanFields));
        }
        if ($cleanFields['phone'] !== '' && !$this->isValidPhone($cleanFields['phone'])) {
            $errors[] = 'Укажите корректный телефон';
        }
        if ($cleanFields['email'] !== '' && !filter_var($cleanFields['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Укажите корректный email';
        }

        $result = $quiz !== null ? $this->findResult($quiz, (int)($payload['result_id'] ?? 0), (string)($payload['result_code'] ?? '')) : null;
        if ($quiz !== null && (((int)($payload['result_id'] ?? 0) > 0) || (string)($payload['result_code'] ?? '') !== '') && $result === null) {
            $errors[] = 'Некорректный результат квиза';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => array_values(array_unique($errors))];
        }

        $leadId = $this->leadRepository->add($this->buildLead($payload, $quiz, $result, $cleanFields));

        return ['success' => true, 'lead_id' => $leadId];
    }

    private function validateRequiredFields(array $quiz, array $fields): array
    {
        $errors = [];
        foreach ((array)($quiz['required_fields'] ?? []) as $field) {
            if (isset(self::FIELD_LABELS[$field]) && ($fields[$field] ?? '') === '') {
                $errors[] = 'Заполните ' . self::FIELD_LABELS[$field];
            }
        }

        return $errors;
    }

    private function buildLead(array $payload, array $quiz, ?array $result, array $fields): array
    {
        $request = Context::getCurrent()->getRequest();
        $utm = is_array($payload['utm'] ?? null) ? $payload['utm'] : [];

        return [
            'quiz_section_id' => (int)$quiz['id'],
            'quiz_code' => (string)$quiz['code'],
            'quiz_name' => (string)$quiz['name'],
            'result_id' => $result !== null ? (int)$result['id'] : '',
            'result_code' => $result !== null ? (string)$result['code'] : '',
            'result_title' => $result !== null ? (string)$result['name'] : '',
            'client_name' => $fields['name'],
            'client_phone' => $fields['phone'],
            'client_email' => $fields['email'],
            'client_messenger' => $fields['messenger'],
            'client_comment' => $fields['comment'],
            'page_url' => $this->cleanUrl($payload['page_url'] ?? ''),
            'referer' => $this->cleanUrl($payload['referer'] ?? ''),
            'utm_source' => $this->cleanString($utm['utm_source'] ?? ''),
            'utm_medium' => $this->cleanString($utm['utm_medium'] ?? ''),
            'utm_campaign' => $this->cleanString($utm['utm_campaign'] ?? ''),
            'utm_content' => $this->cleanString($utm['utm_content'] ?? ''),
            'utm_term' => $this->cleanString($utm['utm_term'] ?? ''),
            'user_agent' => $this->cleanString($request->getUserAgent()),
            'ip' => $this->cleanString($request->getRemoteAddress()),
            'session_id' => session_id(),
            'detail_text' => $this->buildAnswersText($quiz, $payload['answers'] ?? []),
            'answers_data' => Json::encode($payload['answers'] ?? [], JSON_UNESCAPED_UNICODE),
            'email_sent' => 'N',
            'email_sent_at' => '',
        ];
    }


    private function buildAnswersText(array $quiz, mixed $answers): string
    {
        if (!is_array($answers)) {
            return '';
        }

        $questionMap = [];
        foreach ((array)($quiz['questions'] ?? []) as $question) {
            $questionId = (int)($question['id'] ?? 0);
            if ($questionId > 0) {
                $questionMap[$questionId] = $question;
            }
        }

        $blocks = [];
        foreach ($answers as $questionId => $answerValue) {
            $questionId = (int)$questionId;
            $question = $questionMap[$questionId] ?? null;
            if (!is_array($question)) {
                continue;
            }

            $questionName = $this->cleanString($question['name'] ?? '');
            if ($questionName === '') {
                continue;
            }

            $answerTexts = $this->extractAnswerTexts($answerValue);
            if ($answerTexts === []) {
                continue;
            }

            if (count($answerTexts) === 1) {
                $blocks[] = $questionName . "\nОтвет: " . $answerTexts[0];
                continue;
            }

            $blocks[] = $questionName . "\nОтветы:\n- " . implode("\n- ", $answerTexts);
        }

        return mb_substr(implode("\n\n", $blocks), 0, 10000);
    }

    private function extractAnswerTexts(mixed $answerValue): array
    {
        if (is_string($answerValue) || is_numeric($answerValue)) {
            $text = $this->cleanString($answerValue);

            return $text !== '' ? [$text] : [];
        }

        if (!is_array($answerValue)) {
            return [];
        }

        if (array_key_exists('text', $answerValue) || array_key_exists('TEXT', $answerValue)) {
            $text = $this->cleanString($answerValue['text'] ?? $answerValue['TEXT'] ?? '');

            return $text !== '' ? [$text] : [];
        }

        $texts = [];
        foreach ($answerValue as $item) {
            foreach ($this->extractAnswerTexts($item) as $text) {
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return array_values(array_unique($texts));
    }

    private function cleanFields(array $fields): array
    {
        $result = [];
        foreach (array_keys(self::FIELD_LABELS) as $field) {
            $result[$field] = $this->cleanString($fields[$field] ?? '');
        }

        return $result;
    }

    private function findResult(array $quiz, int $resultId, string $resultCode): ?array
    {
        foreach ((array)($quiz['results'] ?? []) as $result) {
            if (($resultId > 0 && (int)$result['id'] === $resultId) || ($resultCode !== '' && (string)$result['code'] === $resultCode)) {
                return $result;
            }
        }

        return null;
    }

    private function checkRateLimit(): bool
    {
        $session = Application::getInstance()->getSession();
        $key = 'kk_quiz_submit_lead_' . md5((string)Context::getCurrent()->getRequest()->getRemoteAddress());
        $data = $session->get($key);
        $now = time();
        if (!is_array($data) || ($now - (int)($data['started_at'] ?? 0)) > self::RATE_LIMIT_TTL) {
            $session->set($key, ['started_at' => $now, 'count' => 1]);
            return true;
        }
        $data['count'] = (int)$data['count'] + 1;
        $session->set($key, $data);

        return $data['count'] <= self::RATE_LIMIT_MAX;
    }

    private function cleanString(mixed $value): string
    {
        $value = is_scalar($value) ? (string)$value : '';
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value;

        return trim(mb_substr($value, 0, 2000));
    }

    private function cleanCode(mixed $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->cleanString($value)) ?? '';
    }

    private function cleanUrl(mixed $value): string
    {
        return mb_substr($this->cleanString($value), 0, 1000);
    }

    private function isValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 10 && strlen($digits) <= 15;
    }
}
