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


    private function attachProductsToResults(array $quiz, array $results): array
    {
        if (
            (bool)($quiz['use_catalog'] ?? false) !== true
            || (int)($quiz['catalog_iblock_id'] ?? 0) <= 0
        ) {
            return $results;
        }

        $iblockId = (int)$quiz['catalog_iblock_id'];
        $limit = 6;

        foreach ($results as &$result) {
            $productIds = is_array($result['catalog_product_ids'] ?? null)
                ? $result['catalog_product_ids']
                : [];

            $products = $this->catalogProductService->getProducts(
                $iblockId,
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
                    $iblockId,
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
        $quizEnabled = (bool)($quiz['use_metrika'] ?? false);
        $globalEnabled = ModuleSettingsService::getBool('yandex_metrika_enabled');

        $counterId = trim((string)($quiz['metrika_counter_id'] ?? ''));
        if ($counterId === '') {
            $counterId = trim(ModuleSettingsService::get('yandex_metrika_counter_id'));
        }

        $formSubmitGoal = trim((string)($quiz['metrika_goal'] ?? ''));
        if ($formSubmitGoal === '') {
            $formSubmitGoal = trim(ModuleSettingsService::get('yandex_metrika_goal'));
        }
        if ($formSubmitGoal === '') {
            $formSubmitGoal = 'kk_quiz_lead';
        }

        $firstAnswerGoal = trim(ModuleSettingsService::get('yandex_metrika_first_answer_goal'));
        if ($firstAnswerGoal === '') {
            $firstAnswerGoal = 'kk_quiz_first_answer';
        }

        $resultReachedGoal = trim(ModuleSettingsService::get('yandex_metrika_result_goal'));
        if ($resultReachedGoal === '') {
            $resultReachedGoal = 'kk_quiz_result_reached';
        }

        $resultCtaClickGoal = trim(ModuleSettingsService::get('yandex_metrika_result_cta_click_goal'));
        if ($resultCtaClickGoal === '') {
            $resultCtaClickGoal = 'kk_quiz_result_cta_click';
        }

        $productClickGoal = trim(ModuleSettingsService::get('yandex_metrika_product_click_goal'));
        if ($productClickGoal === '') {
            $productClickGoal = 'kk_quiz_product_click';
        }

        return [
            'enabled' => ($quizEnabled || $globalEnabled) && $counterId !== '',
            'counter_id' => $counterId,
            'goal' => $formSubmitGoal,
            'goals' => [
                'first_answer' => $firstAnswerGoal,
                'result_reached' => $resultReachedGoal,
                'result_cta_click' => $resultCtaClickGoal,
                'product_click' => $productClickGoal,
                'form_submit' => $formSubmitGoal,
            ],
        ];
    }

    private function buildGoogleAnalyticsSettings(): array
    {
        $formSubmitEventName = trim(ModuleSettingsService::get('google_analytics_event_name'));
        if ($formSubmitEventName === '') {
            $formSubmitEventName = 'generate_lead';
        }

        $firstAnswerEventName = trim(ModuleSettingsService::get('google_analytics_first_answer_event_name'));
        if ($firstAnswerEventName === '') {
            $firstAnswerEventName = 'kk_quiz_first_answer';
        }

        $resultReachedEventName = trim(ModuleSettingsService::get('google_analytics_result_event_name'));
        if ($resultReachedEventName === '') {
            $resultReachedEventName = 'kk_quiz_result_reached';
        }

        $resultCtaClickEventName = trim(ModuleSettingsService::get('google_analytics_result_cta_click_event_name'));
        if ($resultCtaClickEventName === '') {
            $resultCtaClickEventName = 'kk_quiz_result_cta_click';
        }

        $productClickEventName = trim(ModuleSettingsService::get('google_analytics_product_click_event_name'));
        if ($productClickEventName === '') {
            $productClickEventName = 'kk_quiz_product_click';
        }

        return [
            'enabled' => ModuleSettingsService::getBool('google_analytics_enabled'),
            'measurement_id' => trim(ModuleSettingsService::get('google_analytics_measurement_id')),
            'event_name' => $formSubmitEventName,
            'events' => [
                'first_answer' => $firstAnswerEventName,
                'result_reached' => $resultReachedEventName,
                'result_cta_click' => $resultCtaClickEventName,
                'product_click' => $productClickEventName,
                'form_submit' => $formSubmitEventName,
            ],
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
