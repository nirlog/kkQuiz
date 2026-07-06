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
            'metrika' => [
                'enabled' => $quiz['use_metrika'],
                'counter_id' => $quiz['metrika_counter_id'],
                'goal' => $quiz['metrika_goal'] !== '' ? $quiz['metrika_goal'] : 'kk_quiz_lead',
            ],
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

        foreach ($results as &$result) {
            $productIds = is_array($result['catalog_product_ids'] ?? null)
                ? $result['catalog_product_ids']
                : [];

            $result['products'] = $this->catalogProductService->getProducts(
                (int)$quiz['catalog_iblock_id'],
                $productIds,
                6
            );
        }
        unset($result);

        return $results;
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
