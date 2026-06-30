<?php

declare(strict_types=1);

namespace Kk\Quiz\Controller;

use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\Controller;
use Kk\Quiz\Service\LeadService;

final class Api extends Controller
{
    public function configureActions(): array
    {
        return [
            'submitLead' => [
                'prefilters' => [new Csrf()],
            ],
        ];
    }

    public function submitLeadAction(array $payload): array
    {
        try {
            return (new LeadService())->submit($payload);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => [$exception->getMessage() !== '' ? $exception->getMessage() : 'Не удалось сохранить заявку'],
            ];
        }
    }
}
