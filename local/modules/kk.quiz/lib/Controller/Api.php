<?php

declare(strict_types=1);

namespace Kk\Quiz\Controller;

use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Web\Json;
use Kk\Quiz\Service\LeadService;
use Kk\Quiz\Service\QuizService;

final class Api extends Controller
{
    public function configureActions(): array
    {
        return [
            'submitLead' => [
                'prefilters' => [new Csrf()],
            ],
            'getQuiz' => [
                'prefilters' => [new Csrf()],
            ],
        ];
    }

    public function getQuizAction(string $quizCode = ''): array
    {
        $quizCode = $this->normalizeQuizCodeFromRequest($quizCode);
        if ($quizCode === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $quizCode)) {
            return [
                'success' => false,
                'errors' => ['INVALID_QUIZ_CODE'],
            ];
        }

        try {
            $quiz = (new QuizService())->getPublicQuiz($quizCode);
        } catch (\Throwable) {
            $quiz = null;
        }

        if (!is_array($quiz)) {
            return [
                'success' => false,
                'errors' => ['QUIZ_NOT_FOUND'],
            ];
        }

        return [
            'success' => true,
            'quiz' => $quiz,
        ];
    }

    public function submitLeadAction(): array
    {
        try {
            $payload = $this->getPayloadFromRequest();

            return (new LeadService())->submit($payload);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => [$exception->getMessage() !== '' ? $exception->getMessage() : 'Не удалось сохранить заявку'],
            ];
        }
    }

    private function normalizeQuizCodeFromRequest(string $quizCode): string
    {
        $quizCode = trim($quizCode);
        if ($quizCode !== '') {
            return $quizCode;
        }

        $requestQuizCode = $this->getRequest()->getPost('quizCode');
        if (is_string($requestQuizCode)) {
            $quizCode = trim($requestQuizCode);
        }

        if ($quizCode !== '') {
            return $quizCode;
        }

        $input = method_exists($this->getRequest(), 'getInput')
            ? (string)$this->getRequest()->getInput()
            : (string)file_get_contents('php://input');

        if (trim($input) === '') {
            return '';
        }

        try {
            $decoded = Json::decode($input);
        } catch (\Throwable) {
            return '';
        }

        return is_array($decoded) ? trim((string)($decoded['quizCode'] ?? '')) : '';
    }

    private function getPayloadFromRequest(): array
    {
        $request = $this->getRequest();

        $postPayload = $request->getPost('payload');
        if (is_array($postPayload)) {
            return $postPayload;
        }

        if (is_string($postPayload) && $postPayload !== '') {
            try {
                $decoded = Json::decode($postPayload);

                return is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                return [];
            }
        }

        $input = method_exists($request, 'getInput')
            ? (string)$request->getInput()
            : (string)file_get_contents('php://input');

        if (trim($input) === '') {
            return [];
        }

        try {
            $decoded = Json::decode($input);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $payload = $decoded['payload'] ?? $decoded;

        return is_array($payload) ? $payload : [];
    }
}
