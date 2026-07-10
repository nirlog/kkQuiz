<?php

use Bitrix\Main\Loader;
use Kk\Quiz\Service\QuizService;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

final class KkQuizComponent extends CBitrixComponent
{
    public function onPrepareComponentParams($arParams): array
    {
        $displayMode = (string)($arParams['DISPLAY_MODE'] ?? 'block');
        if (!in_array($displayMode, ['block', 'popup', 'button', 'loader'], true)) {
            $displayMode = 'block';
        }

        return [
            'QUIZ_CODE' => trim((string)($arParams['QUIZ_CODE'] ?? '')),
            'DISPLAY_MODE' => $displayMode,
        ];
    }

    public function executeComponent(): void
    {
        $this->arResult = [
            'QUIZ' => null,
            'DISPLAY_MODE' => $this->arParams['DISPLAY_MODE'],
            'IS_LOADER' => $this->arParams['DISPLAY_MODE'] === 'loader',
            'ERROR' => null,
        ];

        if ($this->arParams['DISPLAY_MODE'] === 'loader') {
            $this->includeComponentTemplate();

            return;
        }

        if (!Loader::includeModule('kk.quiz') || $this->arParams['QUIZ_CODE'] === '') {
            $this->arResult['ERROR'] = 'QUIZ_NOT_FOUND';
            $this->includeComponentTemplate();

            return;
        }

        $service = new QuizService();
        $quiz = $service->getPublicQuiz($this->arParams['QUIZ_CODE']);
        if (!is_array($quiz)) {
            $this->arResult['ERROR'] = 'QUIZ_NOT_FOUND';
            $this->includeComponentTemplate();

            return;
        }

        $this->arResult['QUIZ'] = $quiz;
        $this->includeComponentTemplate();
    }
}
