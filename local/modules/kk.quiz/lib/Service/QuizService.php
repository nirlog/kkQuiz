<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Kk\Quiz\Repository\QuizRepository;

final class QuizService
{
    public function __construct(private ?QuizRepository $quizRepository = null)
    {
        $this->quizRepository ??= new QuizRepository();
    }

    public function getPublicQuiz(string $code): ?array
    {
        return $this->quizRepository?->getQuizByCode($code);
    }
}
