<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Json;
use Kk\Quiz\Iblock\Installer;
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
    private const INPUT_QUESTION_TYPES = ['text', 'textarea', 'phone', 'email'];
    private const RATE_LIMIT_TTL = 60;
    private const RATE_LIMIT_MAX = 3;
    private const RATE_LIMIT_CACHE_DIR = '/kk.quiz/rate_limit';
    private const VISITOR_COOKIE_NAME = 'kk_quiz_visitor_id';
    private const VISITOR_COOKIE_TTL = 31536000;

    private QuizService $quizService;
    private LeadRepository $leadRepository;
    private TelegramNotificationService $telegramNotificationService;

    public function __construct(
        ?QuizService $quizService = null,
        ?LeadRepository $leadRepository = null,
        ?TelegramNotificationService $telegramNotificationService = null
    ) {
        $this->quizService = $quizService ?? new QuizService();
        $this->leadRepository = $leadRepository ?? new LeadRepository();
        $this->telegramNotificationService = $telegramNotificationService ?? new TelegramNotificationService();
    }

    public function submit(array $payload): array
    {
        $errors = [];
        $quizCode = $this->cleanCode($payload['quiz_code'] ?? '');

        if (
            ModuleSettingsService::getBool('honeypot_enabled')
            && $this->cleanString($payload['website'] ?? $payload['honeypot'] ?? '') !== ''
        ) {
            return ['success' => false, 'errors' => ['Заявка отклонена']];
        }

        if (ModuleSettingsService::getBool('rate_limit_enabled')) {
            $rateLimit = $this->checkRateLimit($quizCode);

            if (($rateLimit['allowed'] ?? false) !== true) {
                return [
                    'success' => false,
                    'errors' => [$this->buildRateLimitMessage((int)($rateLimit['retry_after'] ?? 0))],
                ];
            }
        }

        if ($quizCode === '') {
            $errors[] = 'Не указан код квиза';
        }
        $quiz = $quizCode !== '' ? $this->quizService->getPublicQuiz($quizCode) : null;
        if ($quiz === null) {
            $errors[] = 'Квиз не найден или неактивен';
        }
        if ($quiz !== null && ($quiz['privacy']['required'] ?? false) === true && !$this->isAgreementAccepted($payload['agreement_accepted'] ?? null)) {
            $errors[] = 'Необходимо согласие с политикой обработки персональных данных.';
        }

        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $cleanFields = $this->cleanFields($fields);
        if ($quiz !== null) {
            $cleanFields = $this->filterFieldsByVisibleFormFields($quiz, $cleanFields);
            $errors = array_merge($errors, $this->validateRequiredFields($quiz, $cleanFields));
        }
        if ($cleanFields['phone'] !== '') {
            $normalizedPhone = $this->normalizePhone($cleanFields['phone']);
            if ($normalizedPhone === '') {
                $errors[] = 'Укажите корректный телефон';
            } else {
                $cleanFields['phone'] = $normalizedPhone;
            }
        }
        if ($cleanFields['email'] !== '' && !filter_var($cleanFields['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Укажите корректный email';
        }

        $result = null;
        $hasRequestedResult = ((int)($payload['result_id'] ?? 0) > 0) || (string)($payload['result_code'] ?? '') !== '';
        if ($quiz !== null && $hasRequestedResult) {
            $result = $this->findResult(
                $quiz,
                (int)($payload['result_id'] ?? 0),
                (string)($payload['result_code'] ?? '')
            );

            if ($result === null) {
                $errors[] = 'Некорректный результат квиза';
            } elseif (!$this->isResultReachableByAnswers($quiz, $payload['answers'] ?? [], $result)) {
                $errors[] = 'Результат квиза не соответствует выбранным ответам';
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => array_values(array_unique($errors))];
        }

        $quiz['email_to'] = $this->quizService->getQuizEmailTo($quizCode);

        $lead = $this->buildLead($payload, $quiz, $result, $cleanFields);
        $leadId = $this->leadRepository->add($lead);
        if ($this->sendEmail($quiz, $lead, $leadId)) {
            $this->leadRepository->markEmailSent($leadId);
        }

        $this->sendTelegram($lead, $leadId);

        return ['success' => true, 'lead_id' => $leadId];
    }


    private function sendEmail(array $quiz, array $lead, int $leadId): bool
    {
        if (!ModuleSettingsService::getBool('email_enabled')) {
            return false;
        }

        $emailTo = $this->resolveEmailTo($quiz['email_to'] ?? '');
        if ($emailTo === '' || !class_exists('CEvent')) {
            return false;
        }

        $leadAdminUrl = ModuleSettingsService::getBool('email_admin_link_enabled')
            ? $this->buildLeadAdminUrl($leadId)
            : '';

        $fields = [
            'EMAIL_TO' => $emailTo,
            'LEAD_ID' => $leadId,
            'LEAD_ADMIN_URL' => $leadAdminUrl,
            'LEAD_ADMIN_BLOCK' => $leadAdminUrl !== ''
                ? "Заявка в админке:\n" . $leadAdminUrl
                : '',
            'QUIZ_NAME' => $this->cleanEmailField($lead['quiz_name'] ?? ''),
            'QUIZ_CODE' => $this->cleanEmailField($lead['quiz_code'] ?? ''),
            'RESULT_TITLE' => $this->cleanEmailField($lead['result_title'] ?? ''),
            'CLIENT_NAME' => $this->cleanEmailField($lead['client_name'] ?? ''),
            'CLIENT_PHONE' => $this->cleanEmailField($lead['client_phone'] ?? ''),
            'CLIENT_EMAIL' => $this->cleanEmailField($lead['client_email'] ?? ''),
            'CLIENT_MESSENGER' => $this->cleanEmailField($lead['client_messenger'] ?? ''),
            'CLIENT_COMMENT' => $this->cleanEmailField($lead['client_comment'] ?? ''),
            'ANSWERS_TEXT' => $this->cleanEmailText($lead['detail_text'] ?? '', 10000),
            'PAGE_URL' => $this->cleanEmailField($lead['page_url'] ?? ''),
            'UTM_TEXT' => $this->buildUtmText($lead),
        ];

        try {
            $siteId = defined('SITE_ID') && is_string(SITE_ID) && SITE_ID !== '' ? SITE_ID : 's1';
            if (method_exists('CEvent', 'SendImmediate')) {
                return (bool)\CEvent::SendImmediate('KK_QUIZ_LEAD', $siteId, $fields);
            }

            return (bool)\CEvent::Send('KK_QUIZ_LEAD', $siteId, $fields);
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildLeadAdminUrl(int $leadId): string
    {
        if ($leadId <= 0) {
            return '';
        }

        $iblockId = $this->leadRepository->getLeadsIblockId();
        if ($iblockId === null || $iblockId <= 0) {
            return '';
        }

        $request = Context::getCurrent()->getRequest();
        $host = trim((string)$request->getHttpHost());
        if ($host === '') {
            return '';
        }

        $scheme = $request->isHttps() ? 'https' : 'http';

        return sprintf(
            '%s://%s/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=%d&type=%s&ID=%d&lang=%s',
            $scheme,
            $host,
            $iblockId,
            rawurlencode(Installer::IBLOCK_TYPE_ID),
            $leadId,
            rawurlencode(defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru')
        );
    }

    private function sendTelegram(array $lead, int $leadId): bool
    {
        if (!ModuleSettingsService::getBool('telegram_enabled')) {
            return false;
        }

        $message = $this->buildTelegramLeadMessage($lead, $leadId);
        if ($message === '') {
            $this->leadRepository->markTelegramFailed($leadId, 'Пустой текст Telegram-сообщения');

            return false;
        }

        try {
            $result = $this->telegramNotificationService->sendMessage(
                ModuleSettingsService::getAll(),
                $message
            );

            if (($result['success'] ?? false) === true) {
                $this->leadRepository->markTelegramSent($leadId);

                return true;
            }

            $error = (string)($result['message'] ?? 'Telegram-сообщение не отправлено');
            $this->leadRepository->markTelegramFailed($leadId, $error);

            return false;
        } catch (\Throwable) {
            $this->leadRepository->markTelegramFailed($leadId, 'Ошибка отправки Telegram-сообщения');

            return false;
        }
    }

    private function buildTelegramLeadMessage(array $lead, int $leadId): string
    {
        $lines = [
            'Новая заявка квиза #' . $leadId,
            '',
            'Квиз: ' . $this->cleanTelegramLine($lead['quiz_name'] ?? ''),
            'Результат: ' . $this->cleanTelegramLine($lead['result_title'] ?? ''),
            '',
            'Клиент:',
            'Имя: ' . $this->cleanTelegramLine($lead['client_name'] ?? ''),
            'Телефон: ' . $this->cleanTelegramLine($lead['client_phone'] ?? ''),
            'Email: ' . $this->cleanTelegramLine($lead['client_email'] ?? ''),
            'Мессенджер: ' . $this->cleanTelegramLine($lead['client_messenger'] ?? ''),
            '',
            'Комментарий:',
            $this->cleanTelegramLine($lead['client_comment'] ?? ''),
            '',
            'Ответы:',
            $this->cleanTelegramText($lead['detail_text'] ?? ''),
            '',
            'Страница:',
            $this->cleanTelegramLine($lead['page_url'] ?? ''),
            '',
            'UTM:',
            $this->cleanTelegramLine($this->buildUtmText($lead)),
            '',
            'Заявка в админке:',
            $this->cleanTelegramLine($this->buildLeadAdminUrl($leadId)),
        ];

        $message = trim(implode("\n", $lines));
        if (mb_strlen($message) > 3900) {
            $message = mb_substr($message, 0, 3900) . '...';
        }

        return $message;
    }

    private function cleanTelegramLine(mixed $value): string
    {
        $value = $this->cleanString($value);

        return $value !== '' ? $value : '—';
    }

    private function cleanTelegramText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '—';
        }

        $value = (string)$value;
        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? $value;

        $lines = preg_split('/\n/u', $value) ?: [];
        $lines = array_map(
            static fn($line) => trim(preg_replace('/[ \t]+/u', ' ', (string)$line) ?? (string)$line),
            $lines
        );

        $cleanLines = [];
        $previousEmpty = false;
        foreach ($lines as $line) {
            $isEmpty = $line === '';
            if ($isEmpty && $previousEmpty) {
                continue;
            }

            $cleanLines[] = $line;
            $previousEmpty = $isEmpty;
        }

        $value = trim(implode("\n", $cleanLines));
        if ($value === '') {
            return '—';
        }

        return mb_substr($value, 0, 10000);
    }

    private function resolveEmailTo(mixed $quizEmailTo): string
    {
        $emailTo = $this->normalizeEmailTo($quizEmailTo);

        if ($emailTo !== '') {
            return $emailTo;
        }

        return $this->normalizeEmailTo(ModuleSettingsService::get('email_to'));
    }

    private function normalizeEmailTo(mixed $value): string
    {
        $value = $this->cleanString($value);
        if ($value === '') {
            return '';
        }

        $emails = preg_split('/[,;]+/', $value) ?: [];
        $validEmails = [];
        foreach ($emails as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validEmails[] = $email;
            }
        }

        return implode(', ', array_unique($validEmails));
    }

    private function buildUtmText(array $lead): string
    {
        $labels = [
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
            'utm_content' => 'utm_content',
            'utm_term' => 'utm_term',
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            $value = $this->cleanEmailField($lead[$key] ?? '');
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }

    private function cleanEmailField(mixed $value, int $limit = 2000): string
    {
        return mb_substr($this->cleanString($value), 0, $limit);
    }


    private function cleanEmailText(mixed $value, int $limit = 10000): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = (string)$value;
        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? $value;

        $lines = preg_split('/\n/u', $value) ?: [];
        $lines = array_map(
            static fn($line) => trim(preg_replace('/[ \t]+/u', ' ', (string)$line) ?? (string)$line),
            $lines
        );

        $cleanLines = [];
        $previousEmpty = false;
        foreach ($lines as $line) {
            $isEmpty = $line === '';
            if ($isEmpty && $previousEmpty) {
                continue;
            }

            $cleanLines[] = $line;
            $previousEmpty = $isEmpty;
        }

        $value = trim(implode("\n", $cleanLines));

        return mb_substr($value, 0, $limit);
    }


    private function isAgreementAccepted(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtoupper(trim($value)), ['Y', 'YES', 'TRUE', '1'], true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }

    private function validateRequiredFields(array $quiz, array $fields): array
    {
        $errors = [];
        $visibleFields = $this->getVisibleFormFields($quiz);

        foreach ((array)($quiz['required_fields'] ?? []) as $field) {
            if (
                is_string($field)
                && in_array($field, $visibleFields, true)
                && isset(self::FIELD_LABELS[$field])
                && ($fields[$field] ?? '') === ''
            ) {
                $errors[] = 'Заполните ' . self::FIELD_LABELS[$field];
            }
        }

        return $errors;
    }


    private function filterFieldsByVisibleFormFields(array $quiz, array $fields): array
    {
        $visibleFields = $this->getVisibleFormFields($quiz);

        foreach (array_keys(self::FIELD_LABELS) as $field) {
            if (!in_array($field, $visibleFields, true)) {
                $fields[$field] = '';
            }
        }

        return $fields;
    }

    private function getVisibleFormFields(array $quiz): array
    {
        $allowedFields = array_keys(self::FIELD_LABELS);
        $formFields = [];

        foreach ((array)($quiz['form_fields'] ?? []) as $field) {
            if (is_string($field) && in_array($field, $allowedFields, true)) {
                $formFields[] = $field;
            }
        }

        $formFields = array_values(array_unique($formFields));

        return $formFields !== [] ? $formFields : ['name', 'phone', 'email'];
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
            'status' => 'new',
            'manager_note' => '',
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
            'answers_data' => Json::encode(
                $this->normalizeAnswersData($quiz, $payload['answers'] ?? []),
                JSON_UNESCAPED_UNICODE
            ),
            'agreement_accepted' => $this->isAgreementAccepted($payload['agreement_accepted'] ?? null) ? 'Y' : 'N',
            'privacy_url' => (string)($quiz['privacy']['url'] ?? ''),
            'email_sent' => 'N',
            'email_sent_at' => '',
            'telegram_sent' => 'N',
            'telegram_sent_at' => '',
            'telegram_error' => '',
        ];
    }



    private function normalizeAnswersData(array $quiz, mixed $answers): array
    {
        if (!is_array($answers)) {
            return [];
        }

        $questionMap = [];
        foreach ((array)($quiz['questions'] ?? []) as $question) {
            $questionId = (int)($question['id'] ?? 0);
            if ($questionId > 0 && is_array($question)) {
                $questionMap[$questionId] = $question;
            }
        }

        $result = [];
        foreach ($answers as $questionId => $answerValue) {
            $questionId = (int)$questionId;
            $question = $questionMap[$questionId] ?? null;
            if (!is_array($question)) {
                continue;
            }

            $normalizedValue = $this->normalizeAnswerValue($question, $answerValue);
            if ($normalizedValue === null || $normalizedValue === [] || $normalizedValue === '') {
                continue;
            }

            $result[(string)$questionId] = $normalizedValue;
        }

        return $result;
    }

    private function normalizeAnswerValue(array $question, mixed $answerValue): mixed
    {
        if ($this->isInputQuestion($question)) {
            return $this->normalizeInputAnswerValue($answerValue);
        }

        if (!is_array($answerValue)) {
            return null;
        }

        if (array_is_list($answerValue)) {
            $items = [];
            foreach ($answerValue as $selectedItem) {
                $normalizedItem = $this->normalizeSelectedAnswerItem($question, $selectedItem);
                if ($normalizedItem !== null) {
                    $items[] = $normalizedItem;
                }
            }

            return $items;
        }

        return $this->normalizeSelectedAnswerItem($question, $answerValue);
    }

    private function normalizeInputAnswerValue(mixed $answerValue): ?array
    {
        if (is_string($answerValue) || is_numeric($answerValue)) {
            $value = $this->cleanString($answerValue);

            return $value !== '' ? ['value' => $value] : null;
        }

        if (is_array($answerValue) && array_key_exists('value', $answerValue)) {
            $value = $this->cleanString($answerValue['value']);

            return $value !== '' ? ['value' => $value] : null;
        }

        return null;
    }

    private function normalizeSelectedAnswerItem(array $question, mixed $selectedItem): ?array
    {
        $answer = $this->findConfiguredAnswer($question, $selectedItem);
        if ($answer === null) {
            return null;
        }

        $normalized = [];
        $code = $this->cleanString($answer['code'] ?? $answer['CODE'] ?? '');
        if ($code !== '') {
            $normalized['code'] = $code;
        }

        $sort = $answer['sort'] ?? $answer['SORT'] ?? null;
        if (is_numeric($sort)) {
            $normalized['sort'] = (int)$sort;
        }

        $index = $this->findConfiguredAnswerIndex($question, $answer);
        if ($index !== null) {
            $normalized['index'] = $index;
        }

        return $normalized !== [] ? $normalized : null;
    }

    private function findConfiguredAnswerIndex(array $question, array $targetAnswer): ?int
    {
        $answers = array_values((array)($question['answers'] ?? []));
        foreach ($answers as $index => $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $targetCode = $this->cleanString($targetAnswer['code'] ?? $targetAnswer['CODE'] ?? '');
            $answerCode = $this->cleanString($answer['code'] ?? $answer['CODE'] ?? '');
            if ($targetCode !== '' && $answerCode !== '' && $targetCode === $answerCode) {
                return (int)$index;
            }

            $targetSort = $targetAnswer['sort'] ?? $targetAnswer['SORT'] ?? null;
            $answerSort = $answer['sort'] ?? $answer['SORT'] ?? null;
            if (is_numeric($targetSort) && is_numeric($answerSort) && (int)$targetSort === (int)$answerSort) {
                return (int)$index;
            }
        }

        return null;
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

            $answerTexts = $this->resolveAnswerTexts($question, $answerValue);
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

    private function resolveAnswerTexts(array $question, mixed $answerValue): array
    {
        if ($this->isInputQuestion($question)) {
            return $this->extractInputAnswerTexts($answerValue);
        }

        if (!is_array($answerValue)) {
            return [];
        }

        $selectedItems = array_is_list($answerValue) ? $answerValue : [$answerValue];
        $texts = [];

        foreach ($selectedItems as $selectedItem) {
            $answer = $this->findConfiguredAnswer($question, $selectedItem);
            if ($answer === null) {
                continue;
            }

            $text = $this->cleanString($answer['text'] ?? $answer['TEXT'] ?? '');
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return array_values(array_unique($texts));
    }


    private function isResultReachableByAnswers(array $quiz, mixed $answers, array $result): bool
    {
        if (!is_array($answers)) {
            return false;
        }

        $targetResultId = (int)($result['id'] ?? 0);
        $targetResultCode = $this->cleanString($result['code'] ?? '');
        if ($targetResultId <= 0 && $targetResultCode === '') {
            return false;
        }

        $questionMap = [];
        foreach ((array)($quiz['questions'] ?? []) as $question) {
            $questionId = (int)($question['id'] ?? 0);
            if ($questionId > 0 && is_array($question)) {
                $questionMap[$questionId] = $question;
            }
        }

        foreach ($answers as $questionId => $answerValue) {
            $questionId = (int)$questionId;
            $question = $questionMap[$questionId] ?? null;
            if (!is_array($question)) {
                continue;
            }

            if ($this->questionPointsToDefaultResult($question, $targetResultId, $targetResultCode)) {
                return true;
            }

            if ($this->isInputQuestion($question)) {
                continue;
            }

            $selectedItems = is_array($answerValue) && array_is_list($answerValue)
                ? $answerValue
                : [$answerValue];

            foreach ($selectedItems as $selectedItem) {
                $answer = $this->findConfiguredAnswer($question, $selectedItem);
                if ($answer === null) {
                    continue;
                }

                if ($this->answerPointsToResult($answer, $targetResultId, $targetResultCode)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function questionPointsToDefaultResult(array $question, int $targetResultId, string $targetResultCode): bool
    {
        $defaultResultId = (int)($question['default_result_id'] ?? 0);

        return $targetResultId > 0 && $defaultResultId === $targetResultId;
    }

    private function answerPointsToResult(array $answer, int $targetResultId, string $targetResultCode): bool
    {
        $answerResultId = (int)($answer['result_id'] ?? $answer['RESULT_ID'] ?? 0);
        if ($targetResultId > 0 && $answerResultId === $targetResultId) {
            return true;
        }

        $answerResultCode = $this->cleanString($answer['result_code'] ?? $answer['RESULT_CODE'] ?? '');
        if ($targetResultCode !== '' && $answerResultCode !== '' && $answerResultCode === $targetResultCode) {
            return true;
        }

        return false;
    }

    private function isInputQuestion(array $question): bool
    {
        $type = strtolower((string)($question['question_type'] ?? ''));

        return in_array($type, self::INPUT_QUESTION_TYPES, true);
    }

    private function extractInputAnswerTexts(mixed $answerValue): array
    {
        if (is_string($answerValue) || is_numeric($answerValue)) {
            $text = $this->cleanString($answerValue);

            return $text !== '' ? [$text] : [];
        }

        if (is_array($answerValue) && array_key_exists('value', $answerValue)) {
            $text = $this->cleanString($answerValue['value']);

            return $text !== '' ? [$text] : [];
        }

        return [];
    }

    private function findConfiguredAnswer(array $question, mixed $selectedItem): ?array
    {
        $answers = array_values((array)($question['answers'] ?? []));
        if ($answers === []) {
            return null;
        }

        $selected = is_array($selectedItem) ? $selectedItem : ['code' => $selectedItem];
        $code = $this->cleanString($selected['code'] ?? $selected['CODE'] ?? '');
        if ($code !== '') {
            foreach ($answers as $answer) {
                if (is_array($answer) && $this->cleanString($answer['code'] ?? $answer['CODE'] ?? '') === $code) {
                    return $answer;
                }
            }
        }

        $index = $selected['index'] ?? $selected['INDEX'] ?? null;
        if (is_numeric($index)) {
            $index = (int)$index;
            if (isset($answers[$index]) && is_array($answers[$index])) {
                return $answers[$index];
            }
        }

        $sort = $selected['sort'] ?? $selected['SORT'] ?? null;
        if (is_numeric($sort)) {
            foreach ($answers as $answer) {
                if (is_array($answer) && (int)($answer['sort'] ?? $answer['SORT'] ?? 0) === (int)$sort) {
                    return $answer;
                }
            }
        }

        return null;
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

    private function getIntSetting(string $name, int $default, int $min, int $max): int
    {
        $value = ModuleSettingsService::get($name);

        if (!is_numeric($value)) {
            return $default;
        }

        $value = (int)$value;

        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    private function checkRateLimit(string $quizCode = ''): array
    {
        $ttl = $this->getIntSetting('rate_limit_ttl', self::RATE_LIMIT_TTL, 1, 86400);
        $max = $this->getIntSetting('rate_limit_max', self::RATE_LIMIT_MAX, 1, 1000);

        $visitorId = $this->getRateLimitVisitorId();

        $cacheKey = $this->buildRateLimitCacheKey($visitorId, $quizCode);
        $data = $this->readRateLimitBucket($cacheKey, $ttl);

        $now = time();
        $startedAt = is_array($data) ? (int)($data['started_at'] ?? 0) : 0;

        if (!is_array($data) || ($now - $startedAt) >= $ttl) {
            $this->writeRateLimitBucket($cacheKey, [
                'started_at' => $now,
                'count' => 1,
            ], $ttl);

            return [
                'allowed' => true,
                'retry_after' => 0,
            ];
        }

        $count = (int)($data['count'] ?? 0) + 1;
        $retryAfter = max(1, $ttl - ($now - $startedAt));

        $this->writeRateLimitBucket($cacheKey, [
            'started_at' => $startedAt > 0 ? $startedAt : $now,
            'count' => $count,
        ], $retryAfter);

        if ($count <= $max) {
            return [
                'allowed' => true,
                'retry_after' => 0,
            ];
        }

        return [
            'allowed' => false,
            'retry_after' => $retryAfter,
        ];
    }

    private function getRateLimitVisitorId(): string
    {
        $request = Context::getCurrent()->getRequest();
        $visitorId = $this->cleanVisitorId((string)$request->getCookie(self::VISITOR_COOKIE_NAME));

        if ($visitorId !== '') {
            return $visitorId;
        }

        try {
            $visitorId = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $visitorId = md5(uniqid('kk_quiz_', true));
        }

        $this->setVisitorCookie($visitorId);

        return $visitorId;
    }

    private function cleanVisitorId(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[a-f0-9]{32,64}$/i', $value) === 1 ? strtolower($value) : '';
    }

    private function setVisitorCookie(string $visitorId): void
    {
        if (headers_sent()) {
            return;
        }

        $secure = Context::getCurrent()->getRequest()->isHttps();

        setcookie(self::VISITOR_COOKIE_NAME, $visitorId, [
            'expires' => time() + self::VISITOR_COOKIE_TTL,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[self::VISITOR_COOKIE_NAME] = $visitorId;
    }

    private function buildRateLimitCacheKey(string $visitorId, string $quizCode): string
    {
        return 'kk_quiz_submit_lead_' . md5($visitorId . '|' . $quizCode);
    }

    private function readRateLimitBucket(string $cacheKey, int $ttl): array
    {
        $cache = Cache::createInstance();

        if (!$cache->initCache($ttl, $cacheKey, self::RATE_LIMIT_CACHE_DIR)) {
            return [];
        }

        $data = $cache->getVars();

        return is_array($data) ? $data : [];
    }

    private function writeRateLimitBucket(string $cacheKey, array $data, int $ttl): void
    {
        $ttl = max(1, $ttl);

        $cache = Cache::createInstance();
        $cache->clean($cacheKey, self::RATE_LIMIT_CACHE_DIR);

        if ($cache->startDataCache($ttl, $cacheKey, self::RATE_LIMIT_CACHE_DIR)) {
            $cache->endDataCache($data);
        }
    }

    private function buildRateLimitMessage(int $retryAfter): string
    {
        $retryAfter = max(1, $retryAfter);

        return 'Слишком частая отправка формы. Попробуйте через ' . $this->formatDuration($retryAfter) . '.';
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(1, $seconds);

        if ($seconds < 60) {
            return $seconds . ' ' . $this->pluralizeRu($seconds, 'секунду', 'секунды', 'секунд');
        }

        $minutes = (int)ceil($seconds / 60);

        if ($minutes < 60) {
            return $minutes . ' ' . $this->pluralizeRu($minutes, 'минуту', 'минуты', 'минут');
        }

        $hours = (int)ceil($minutes / 60);

        return $hours . ' ' . $this->pluralizeRu($hours, 'час', 'часа', 'часов');
    }

    private function pluralizeRu(int $number, string $one, string $few, string $many): string
    {
        $number = abs($number);
        $mod10 = $number % 10;
        $mod100 = $number % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        if ($mod10 === 1) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
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

    private function normalizePhone(mixed $value): string
    {
        $raw = $this->cleanString($value);
        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10) {
            return '+7' . $digits;
        }

        if (strlen($digits) === 11 && $digits[0] === '8') {
            return '+7' . substr($digits, 1);
        }

        if (strlen($digits) === 11 && $digits[0] === '7') {
            return '+' . $digits;
        }

        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return '+' . $digits;
        }

        return '';
    }
}
