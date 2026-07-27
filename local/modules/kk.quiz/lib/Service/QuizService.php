<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Kk\Quiz\Repository\QuizRepository;

final class QuizService
{
    private QuizRepository $quizRepository;
    private CatalogProductService $catalogProductService;

    public function __construct(
        ?QuizRepository $quizRepository = null,
        ?CatalogProductService $catalogProductService = null
    ) {
        $this->quizRepository = $quizRepository ?? new QuizRepository();
        $this->catalogProductService = $catalogProductService ?? new CatalogProductService();
    }

    public function getPublicQuiz(string $code): ?array
    {
        $quiz = $this->quizRepository->getQuizByCode($code);
        if ($quiz === null) {
            return null;
        }

        $questions = $quiz['questions'];
        $results = $quiz['results'];
        $results = $this->attachProductsToResults($quiz, $results);

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
            'progress_total' => (int)($quiz['progress_total'] ?? 0),
            'success_text' => $quiz['success_text'],
            'theme' => $quiz['theme'] !== '' ? $quiz['theme'] : 'default',
            'appearance' => [
                'theme' => $quiz['theme'] !== '' ? $quiz['theme'] : 'light',
                'max_width' => $quiz['max_width'] ?? '920px',
                'accent_color' => $quiz['accent_color'] ?? '#2563eb',
                'accent_hover_color' => $quiz['accent_hover_color'] ?? '#1d4ed8',
                'active_color' => $quiz['active_color'] ?? '#2563eb',
                'progress_color' => $quiz['progress_color'] ?? '#2563eb',
                'border_radius' => (int)($quiz['border_radius'] ?? 20),
                'container_radius' => (int)($quiz['container_radius'] ?? 24),
                'card_radius' => (int)($quiz['card_radius'] ?? 16),
                'button_radius' => (int)($quiz['button_radius'] ?? 12),
                'input_radius' => (int)($quiz['input_radius'] ?? 10),
                'image_radius' => (int)($quiz['image_radius'] ?? 12),
                'answer_image_ratio' => $quiz['answer_image_ratio'] ?? '4:3',
                'answer_image_fit' => $quiz['answer_image_fit'] ?? 'cover',
            ],
            'form_fields' => $quiz['form_fields'],
            'required_fields' => $quiz['required_fields'],
            'metrika' => $this->buildMetrikaSettings($quiz),
            'google_analytics' => $this->buildGoogleAnalyticsSettings($quiz),
            'catalog' => [
                'enabled' => $quiz['use_catalog'],
                'iblock_id' => $quiz['catalog_iblock_id'],
                'iblock_ids' => $quiz['catalog_iblock_ids'] ?? [],
            ],
            'privacy' => [
                'text' => $quiz['privacy_text'],
                'url' => $quiz['privacy_url'],
                'required' => $quiz['require_agreement'],
            ],
            'first_question_id' => $this->resolveFirstQuestionId(
                $questions,
                (int)($quiz['start_question_id'] ?? 0)
            ),
            'questions' => $this->resolveQuestionAppearance($questions, $quiz),
            'results' => $results,
        ];
    }

    private function resolveQuestionAppearance(array $questions, array $quiz): array
    {
        $globalRatio = (string)($quiz['answer_image_ratio'] ?? '4:3');
        $globalFit = (string)($quiz['answer_image_fit'] ?? 'cover');

        foreach ($questions as &$question) {
            $question['resolved_answer_image_ratio'] = (string)($question['answer_image_ratio'] ?? '') ?: $globalRatio;
            $question['resolved_answer_image_fit'] = (string)($question['answer_image_fit'] ?? '') ?: $globalFit;
        }
        unset($question);

        return $questions;
    }


    private function attachProductsToResults(array $quiz, array $results): array
    {
        if ((bool)($quiz['use_catalog'] ?? false) !== true) {
            return $results;
        }

        $iblockIds = is_array($quiz['catalog_iblock_ids'] ?? null)
            ? $quiz['catalog_iblock_ids']
            : [];
        $iblockIds = array_values(array_filter(array_map('intval', $iblockIds)));

        if ($iblockIds === [] && (int)($quiz['catalog_iblock_id'] ?? 0) > 0) {
            $iblockIds = [(int)$quiz['catalog_iblock_id']];
        }

        if ($iblockIds === []) {
            return $results;
        }

        $limit = 6;

        foreach ($results as &$result) {
            $productIds = is_array($result['catalog_product_ids'] ?? null)
                ? $result['catalog_product_ids']
                : [];

            $products = $this->catalogProductService->getProducts(
                $iblockIds,
                $productIds,
                $limit
            );

            $loadedIds = array_map(
                static fn(array $product): int => (int)($product['id'] ?? 0),
                $products
            );
            $loadedIds = array_values(array_filter($loadedIds));

            $sectionId = (int)($result['catalog_section_id'] ?? 0);
            $remainingLimit = $limit - count($products);

            if ($sectionId > 0 && $remainingLimit > 0) {
                $sectionProducts = $this->catalogProductService->getProductsFromSection(
                    $iblockIds,
                    $sectionId,
                    $loadedIds,
                    $remainingLimit
                );

                $products = array_merge($products, $sectionProducts);
            }

            $result['products'] = array_slice($this->uniqueProducts($products), 0, $limit);
        }
        unset($result);

        return $results;
    }

    private function uniqueProducts(array $products): array
    {
        $result = [];
        $seen = [];

        foreach ($products as $product) {
            $id = (int)($product['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $result[] = $product;
        }

        return $result;
    }


    private function buildMetrikaSettings(array $quiz): array
    {
        $counterId = trim((string)($quiz['metrika_counter_id'] ?? ''));
        $formSubmitGoal = $this->resolveAnalyticsName($quiz['metrika_goal'] ?? '', 'kk_quiz_lead');

        return [
            'enabled' => (bool)($quiz['use_metrika'] ?? false) && $counterId !== '',
            'counter_id' => $counterId,
            'goal' => $formSubmitGoal,
            'goals' => [
                'first_answer' => $this->resolveAnalyticsName($quiz['metrika_first_answer_goal'] ?? '', 'kk_quiz_first_answer'),
                'result_reached' => $this->resolveAnalyticsName($quiz['metrika_result_goal'] ?? '', 'kk_quiz_result_reached'),
                'result_cta_click' => $this->resolveAnalyticsName($quiz['metrika_result_cta_click_goal'] ?? '', 'kk_quiz_result_cta_click'),
                'product_click' => $this->resolveAnalyticsName($quiz['metrika_product_click_goal'] ?? '', 'kk_quiz_recommendation_click'),
                'form_submit' => $formSubmitGoal,
            ],
        ];
    }

    private function buildGoogleAnalyticsSettings(array $quiz): array
    {
        $measurementId = trim((string)($quiz['ga_measurement_id'] ?? ''));
        $formSubmitEventName = $this->resolveAnalyticsName($quiz['ga_form_submit_event_name'] ?? '', 'generate_lead');

        return [
            'enabled' => (bool)($quiz['use_ga'] ?? false) && $measurementId !== '',
            'measurement_id' => $measurementId,
            'event_name' => $formSubmitEventName,
            'events' => [
                'first_answer' => $this->resolveAnalyticsName($quiz['ga_first_answer_event_name'] ?? '', 'kk_quiz_first_answer'),
                'result_reached' => $this->resolveAnalyticsName($quiz['ga_result_event_name'] ?? '', 'kk_quiz_result_reached'),
                'result_cta_click' => $this->resolveAnalyticsName($quiz['ga_result_cta_click_event_name'] ?? '', 'kk_quiz_result_cta_click'),
                'product_click' => $this->resolveAnalyticsName($quiz['ga_product_click_event_name'] ?? '', 'kk_quiz_recommendation_click'),
                'form_submit' => $formSubmitEventName,
            ],
        ];
    }

    private function resolveAnalyticsName(mixed $value, string $fallback): string
    {
        $value = trim((string)$value);

        return $value !== '' ? $value : $fallback;
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

    private function resolveFirstQuestionId(array $questions, int $configuredStartQuestionId): ?int
    {
        if ($configuredStartQuestionId > 0) {
            foreach ($questions as $question) {
                if ((int)($question['id'] ?? 0) === $configuredStartQuestionId) {
                    return $configuredStartQuestionId;
                }
            }
        }

        return $this->getFirstQuestionId($questions);
    }
}
