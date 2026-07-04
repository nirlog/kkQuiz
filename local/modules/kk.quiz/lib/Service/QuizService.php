<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Kk\Quiz\Repository\QuizRepository;

final class QuizService
{
    private QuizRepository $quizRepository;

    public function __construct(?QuizRepository $quizRepository = null)
    {
        $this->quizRepository = $quizRepository ?? new QuizRepository();
    }

    public function getPublicQuiz(string $code): ?array
    {
        $quiz = $this->quizRepository->getQuizByCode($code);
        if ($quiz === null) {
            return null;
        }

        $questions = $quiz['questions'];
        $results = $quiz['results'];

        return [
            'id' => $quiz['id'],
            'code' => $quiz['code'],
            'name' => $quiz['name'],
            'title' => $quiz['title'],
            'subtitle' => $quiz['subtitle'],
            'button_text' => $quiz['button_text'],
            'form_button_text' => $quiz['form_button_text'],
            'form_title' => $quiz['form_title'],
            'form_subtitle' => $quiz['form_subtitle'],
            'start_text' => $quiz['start_text'],
            'success_text' => $quiz['success_text'],
            'theme' => $quiz['theme'] !== '' ? $quiz['theme'] : 'default',
            'form_fields' => $quiz['form_fields'],
            'required_fields' => $quiz['required_fields'],
            'metrika' => $this->buildMetrikaSettings($quiz),
            'google_analytics' => $this->buildGoogleAnalyticsSettings(),
            'catalog' => [
                'enabled' => $quiz['use_catalog'],
                'iblock_id' => $quiz['catalog_iblock_id'],
            ],
            'privacy' => [
                'text' => $quiz['privacy_text'],
                'url' => $quiz['privacy_url'],
                'required' => $quiz['require_agreement'],
            ],
            'first_question_id' => $this->getFirstQuestionId($questions),
            'questions' => $questions,
            'results' => $results,
        ];
    }


    private function buildMetrikaSettings(array $quiz): array
    {
        $quizEnabled = (bool)($quiz['use_metrika'] ?? false);
        $globalEnabled = ModuleSettingsService::getBool('yandex_metrika_enabled');

        $counterId = trim((string)($quiz['metrika_counter_id'] ?? ''));
        if ($counterId === '') {
            $counterId = trim(ModuleSettingsService::get('yandex_metrika_counter_id'));
        }

        $goal = trim((string)($quiz['metrika_goal'] ?? ''));
        if ($goal === '') {
            $goal = trim(ModuleSettingsService::get('yandex_metrika_goal'));
        }

        if ($goal === '') {
            $goal = 'kk_quiz_lead';
        }

        return [
            'enabled' => ($quizEnabled || $globalEnabled) && $counterId !== '',
            'counter_id' => $counterId,
            'goal' => $goal,
        ];
    }

    private function buildGoogleAnalyticsSettings(): array
    {
        $eventName = trim(ModuleSettingsService::get('google_analytics_event_name'));
        if ($eventName === '') {
            $eventName = 'generate_lead';
        }

        return [
            'enabled' => ModuleSettingsService::getBool('google_analytics_enabled'),
            'measurement_id' => trim(ModuleSettingsService::get('google_analytics_measurement_id')),
            'event_name' => $eventName,
        ];
    }


    public function getQuizEmailTo(string $code): string
    {
        $quiz = $this->quizRepository->getQuizByCode($code);

        return is_array($quiz) ? (string)($quiz['email_to'] ?? '') : '';
    }

    private function getFirstQuestionId(array $questions): ?int
    {
        if ($questions === []) {
            return null;
        }

        return (int)$questions[0]['id'];
    }
}
