<?php

declare(strict_types=1);

namespace Kk\Quiz\Controller;

use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Kk\Quiz\Admin\LeadStatusHelper;
use Kk\Quiz\Iblock\Installer;
use Kk\Quiz\Service\LeadService;
use Kk\Quiz\Service\ModuleSettingsService;
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
            'exportQuiz' => [
                'prefilters' => [new Csrf()],
            ],
            'importQuiz' => [
                'prefilters' => [new Csrf()],
            ],
            'exportLeads' => [
                'prefilters' => [new Csrf()],
            ],
            'setLeadStatus' => [
                'prefilters' => [new Csrf()],
            ],
            'cleanupQuizEvents' => [
                'prefilters' => [new Csrf()],
            ],
            'exportQuizStatistics' => [
                'prefilters' => [new Csrf()],
            ],
            'trackEvent' => [
                'prefilters' => [],
            ],
            'testWebhook' => [
                'prefilters' => [new Csrf()],
            ],
            'retryLeadWebhook' => [
                'prefilters' => [new Csrf()],
            ],
            'retryLeadBitrix24' => [
                'prefilters' => [new Csrf()],
            ],
            'testBitrix24' => [
                'prefilters' => [new Csrf()],
            ],
            'retryLeadAmoCrm' => [
                'prefilters' => [new Csrf()],
            ],
            'testAmoCrm' => [
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

    public function exportQuizAction(string $quizCode = ''): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        $quizCode = $this->normalizeQuizCodeFromRequest($quizCode);

        if ($quizCode === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $quizCode)) {
            return [
                'success' => false,
                'errors' => ['INVALID_QUIZ_CODE'],
            ];
        }

        try {
            $export = (new \Kk\Quiz\Service\QuizExportService())->exportByCode($quizCode);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => [$exception->getMessage() !== '' ? $exception->getMessage() : 'EXPORT_FAILED'],
            ];
        }

        if (!is_array($export)) {
            return [
                'success' => false,
                'errors' => ['QUIZ_NOT_FOUND'],
            ];
        }

        return [
            'success' => true,
            'filename' => 'kk-quiz-' . $quizCode . '.json',
            'export' => $export,
        ];
    }

    public function importQuizAction(): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        try {
            $payload = $this->getImportPayloadFromRequest();
            $result = (new \Kk\Quiz\Service\QuizImportService())->import($payload);

            return [
                'success' => true,
                'import' => $result,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => [$exception->getMessage() !== '' ? $exception->getMessage() : 'IMPORT_FAILED'],
            ];
        }
    }

    public function exportLeadsAction(): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        try {
            $export = (new \Kk\Quiz\Service\LeadExportService())->exportCsv();

            return [
                'success' => true,
                'filename' => $export['filename'],
                'content' => $export['content'],
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => [$exception->getMessage() !== '' ? $exception->getMessage() : 'EXPORT_LEADS_FAILED'],
            ];
        }
    }

    public function exportQuizStatisticsAction(): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        try {
            $options = $this->getStatisticsExportOptionsFromRequest();
            $exportService = new \Kk\Quiz\Service\QuizStatisticsExportService();
            $export = ($options['format'] ?? 'csv') === 'xls'
                ? $exportService->exportHtmlXls($options)
                : $exportService->exportCsv($options);

            return [
                'success' => true,
                'filename' => $export['filename'],
                'content' => $export['content'],
            ];
        } catch (\Throwable) {
            return [
                'success' => false,
                'errors' => ['EXPORT_STATISTICS_FAILED'],
            ];
        }
    }

    public function cleanupQuizEventsAction(string $mode = '', string $quizCode = ''): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        $mode = $this->getCleanupModeFromRequest($mode);
        if (!in_array($mode, ['old', 'orphan', 'all', 'events_all', 'leads_all', 'full'], true)) {
            return [
                'success' => false,
                'errors' => ['INVALID_CLEANUP_MODE'],
            ];
        }

        $quizCode = $this->getCleanupQuizCodeFromRequest($quizCode);
        if ($quizCode !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $quizCode) !== 1) {
            return [
                'success' => false,
                'errors' => ['INVALID_QUIZ_CODE'],
            ];
        }

        try {
            $service = new \Kk\Quiz\Service\QuizEventMaintenanceService();
            $response = ['success' => true];

            if ($mode === 'old' || $mode === 'all') {
                $response['old'] = $service->cleanupOldEvents();
            }

            if ($mode === 'orphan' || $mode === 'all') {
                $response['orphan'] = $service->cleanupOrphanQuizEvents();
            }

            if ($mode === 'events_all') {
                $response['events'] = $service->cleanupAllEvents($quizCode !== '' ? $quizCode : null);
            }

            if ($mode === 'leads_all') {
                $response['leads'] = $service->cleanupLeadElements($quizCode !== '' ? $quizCode : null);
            }

            if ($mode === 'full') {
                $response['full'] = $service->cleanupFull($quizCode !== '' ? $quizCode : null);
            }

            foreach (['old', 'orphan', 'events', 'leads', 'full'] as $resultKey) {
                if (isset($response[$resultKey]) && is_array($response[$resultKey]) && ($response[$resultKey]['success'] ?? true) !== true) {
                    $response['success'] = false;
                    $response['errors'] = array_merge($response['errors'] ?? [], (array)($response[$resultKey]['errors'] ?? []));
                }
            }

            return $response;
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => [$exception->getMessage() !== '' ? $exception->getMessage() : 'CLEANUP_QUIZ_EVENTS_FAILED'],
            ];
        }
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

    public function trackEventAction(): array
    {
        try {
            $payload = $this->getTrackingPayloadFromRequest();

            return (new \Kk\Quiz\Service\QuizEventService())->track($payload);
        } catch (\Throwable) {
            return [
                'success' => false,
                'errors' => ['TRACK_FAILED'],
            ];
        }
    }

    public function testWebhookAction(): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        $payload = [
            'event' => 'kk_quiz_webhook_test',
            'module' => 'kk.quiz',
            'version' => 1,
            'created_at' => date('c'),
            'test' => true,
            'lead' => [
                'id' => 0,
                'quiz' => [
                    'code' => 'test',
                    'name' => 'Тестовый квиз',
                ],
                'client' => [
                    'name' => 'Тест',
                    'phone' => '+70000000000',
                    'email' => 'test@example.com',
                ],
                'answers_text' => 'Тестовая отправка webhook',
            ],
        ];

        try {
            return (new \Kk\Quiz\Service\LeadWebhookService())->send($payload);
        } catch (\Throwable) {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'WEBHOOK_TEST_FAILED',
            ];
        }
    }


    public function retryLeadWebhookAction(int $leadId = 0): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        $leadId = $this->getLeadIdFromRequest($leadId);
        if ($leadId <= 0) {
            return [
                'success' => false,
                'errors' => ['LEAD_NOT_FOUND'],
            ];
        }

        if (!ModuleSettingsService::getBool('webhook_enabled')) {
            return $this->disabledIntegrationResult('WEBHOOK_DISABLED', 'Интеграция Webhook отключена.');
        }

        try {
            return (new LeadService())->retryWebhook($leadId);
        } catch (\Throwable) {
            return [
                'success' => false,
                'errors' => ['WEBHOOK_RETRY_FAILED'],
            ];
        }
    }


    public function retryLeadBitrix24Action(int $leadId = 0): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        $leadId = $this->getLeadIdFromRequest($leadId);
        if ($leadId <= 0) {
            return [
                'success' => false,
                'errors' => ['LEAD_NOT_FOUND'],
            ];
        }

        if (!ModuleSettingsService::getBool('bitrix24_enabled')) {
            return $this->disabledIntegrationResult('BITRIX24_DISABLED', 'Интеграция Bitrix24 отключена.');
        }

        try {
            return (new LeadService())->retryBitrix24($leadId);
        } catch (\Throwable) {
            return [
                'success' => false,
                'errors' => ['BITRIX24_RETRY_FAILED'],
            ];
        }
    }

    public function testBitrix24Action(): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        $payload = [
            'event' => 'kk_quiz_bitrix24_test',
            'module' => 'kk.quiz',
            'version' => 1,
            'created_at' => date('c'),
            'test' => true,
            'lead' => [
                'id' => 0,
                'quiz' => [
                    'code' => 'test',
                    'name' => 'Тестовый квиз',
                ],
                'result' => [
                    'title' => 'Тестовая заявка KK Quiz',
                ],
                'client' => [
                    'name' => 'Тест',
                    'phone' => '+70000000000',
                    'email' => 'test@example.com',
                    'comment' => 'Тестовая заявка KK Quiz',
                ],
                'answers_text' => 'Тестовая заявка KK Quiz',
            ],
        ];

        try {
            return (new \Kk\Quiz\Service\Bitrix24LeadService())->send($payload);
        } catch (\Throwable) {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'BITRIX24_TEST_FAILED',
            ];
        }
    }

    public function retryLeadAmoCrmAction(int $leadId = 0): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        $leadId = $this->getLeadIdFromRequest($leadId);
        if ($leadId <= 0) {
            return [
                'success' => false,
                'errors' => ['LEAD_NOT_FOUND'],
            ];
        }

        if (!ModuleSettingsService::getBool('amocrm_enabled')) {
            return $this->disabledIntegrationResult('AMOCRM_DISABLED', 'Интеграция amoCRM отключена.');
        }

        try {
            return (new LeadService())->retryAmoCrm($leadId);
        } catch (\Throwable) {
            return [
                'success' => false,
                'errors' => ['AMOCRM_RETRY_FAILED'],
            ];
        }
    }

    public function setLeadStatusAction(int $leadId = 0, string $status = ''): array
    {
        if (!$this->isAdminAllowed()) {
            return ['success' => false, 'errors' => ['ACCESS_DENIED']];
        }
        if (!$this->getRequest()->isPost()) {
            return ['success' => false, 'errors' => ['STATUS_UPDATE_FAILED']];
        }

        $leadId = $this->getLeadIdFromRequest($leadId);
        $status = $this->getStatusFromRequest($status);
        if ($leadId <= 0) {
            return ['success' => false, 'errors' => ['LEAD_NOT_FOUND']];
        }
        if ($status === '' || !LeadStatusHelper::isKnownStatus($status)) {
            return ['success' => false, 'errors' => ['INVALID_STATUS']];
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId <= 0 || !$this->isLeadExists($iblockId, $leadId)) {
            return ['success' => false, 'errors' => ['LEAD_NOT_FOUND']];
        }

        $property = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $iblockId,
            'CODE' => 'KK_LEAD_STATUS',
        ])->Fetch();
        if (!is_array($property) || (int)($property['ID'] ?? 0) <= 0) {
            return ['success' => false, 'errors' => ['STATUS_PROPERTY_NOT_FOUND']];
        }

        $enum = \CIBlockPropertyEnum::GetList([], [
            'PROPERTY_ID' => (int)$property['ID'],
            'XML_ID' => $status,
        ])->Fetch();
        $enumId = is_array($enum) ? (int)($enum['ID'] ?? 0) : 0;
        if ($enumId <= 0) {
            return ['success' => false, 'errors' => ['STATUS_ENUM_NOT_FOUND']];
        }

        try {
            \CIBlockElement::SetPropertyValuesEx($leadId, $iblockId, ['KK_LEAD_STATUS' => $enumId]);
        } catch (\Throwable) {
            return ['success' => false, 'errors' => ['STATUS_UPDATE_FAILED']];
        }
        $updatedStatus = \CIBlockElement::GetProperty($iblockId, $leadId, [], [
            'CODE' => 'KK_LEAD_STATUS',
        ])->Fetch();
        if (!is_array($updatedStatus) || (int)($updatedStatus['VALUE_ENUM_ID'] ?? 0) !== $enumId) {
            return ['success' => false, 'errors' => ['STATUS_UPDATE_FAILED']];
        }

        return [
            'success' => true,
            'lead_id' => $leadId,
            'status' => $status,
            'status_label' => LeadStatusHelper::label($status),
        ];
    }

    public function testAmoCrmAction(): array
    {
        if (!$this->isAdminAllowed()) {
            return [
                'success' => false,
                'errors' => ['ACCESS_DENIED'],
            ];
        }

        $payload = [
            'event' => 'kk_quiz_amocrm_test',
            'module' => 'kk.quiz',
            'version' => 1,
            'created_at' => date('c'),
            'test' => true,
            'lead' => [
                'id' => 0,
                'quiz' => [
                    'code' => 'test',
                    'name' => 'Тестовый квиз',
                ],
                'result' => [
                    'title' => 'Тестовая заявка KK Quiz',
                ],
                'client' => [
                    'name' => 'Тест',
                    'phone' => '+70000000000',
                    'email' => 'test@example.com',
                    'comment' => 'Тестовая заявка KK Quiz',
                ],
                'page' => [
                    'url' => 'https://example.com/kk-quiz-test',
                ],
                'answers_text' => 'Тестовая заявка KK Quiz',
            ],
        ];

        try {
            return (new \Kk\Quiz\Service\AmoCrmLeadService())->send($payload);
        } catch (\Throwable) {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'AMOCRM_TEST_FAILED',
            ];
        }
    }


    private function isAdminAllowed(): bool
    {
        global $USER;

        return is_object($USER) && method_exists($USER, 'IsAdmin') && $USER->IsAdmin();
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


    private function getLeadIdFromRequest(int $leadId): int
    {
        if ($leadId > 0) {
            return $leadId;
        }

        $requestLeadId = $this->getRequest()->getPost('lead_id');
        if (is_scalar($requestLeadId) && (int)$requestLeadId > 0) {
            return (int)$requestLeadId;
        }

        $input = method_exists($this->getRequest(), 'getInput')
            ? (string)$this->getRequest()->getInput()
            : (string)file_get_contents('php://input');

        if (trim($input) === '') {
            return 0;
        }

        try {
            $decoded = Json::decode($input);
        } catch (\Throwable) {
            return 0;
        }

        if (!is_array($decoded)) {
            return 0;
        }

        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : $decoded;

        return (int)($payload['lead_id'] ?? $payload['leadId'] ?? 0);
    }

    private function getStatusFromRequest(string $status): string
    {
        $status = trim($status);
        if ($status === '') {
            foreach (['status', 'status_xml_id'] as $key) {
                $value = $this->getRequest()->getPost($key);
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $status = trim((string)$value);
                    break;
                }
            }
        }

        if ($status === '') {
            $input = method_exists($this->getRequest(), 'getInput')
                ? (string)$this->getRequest()->getInput()
                : (string)file_get_contents('php://input');
            if (trim($input) !== '') {
                try {
                    $decoded = Json::decode($input);
                } catch (\Throwable) {
                    $decoded = null;
                }
                if (is_array($decoded)) {
                    $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : $decoded;
                    $value = $payload['status'] ?? $payload['status_xml_id'] ?? '';
                    $status = is_scalar($value) ? trim((string)$value) : '';
                }
            }
        }

        return preg_match('/^[a-z0-9_]{2,50}$/', $status) === 1 ? $status : '';
    }

    private function getLeadsIblockId(): int
    {
        if (!Loader::includeModule('iblock')) {
            return 0;
        }
        $iblock = \CIBlock::GetList([], [
            'TYPE' => Installer::IBLOCK_TYPE_ID,
            'CODE' => Installer::LEADS_IBLOCK_CODE,
        ])->Fetch();

        return is_array($iblock) ? (int)($iblock['ID'] ?? 0) : 0;
    }

    private function isLeadExists(int $iblockId, int $leadId): bool
    {
        return is_array(\CIBlockElement::GetList([], [
            'ID' => $leadId,
            'IBLOCK_ID' => $iblockId,
        ], false, false, ['ID'])->Fetch());
    }

    private function getImportPayloadFromRequest(): array
    {
        $request = $this->getRequest();

        $postPayload = $request->getPost('export');
        if (is_array($postPayload)) {
            return $postPayload;
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

        $payload = $decoded['export'] ?? $decoded['payload'] ?? $decoded;

        return is_array($payload) ? $payload : [];
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

    private function getStatisticsExportOptionsFromRequest(): array
    {
        $request = $this->getRequest();
        $options = [];

        foreach (['date_from', 'date_to', 'period_label', 'format', 'quiz_code', 'quiz_label'] as $key) {
            $value = $request->getPost($key);
            if (is_scalar($value)) {
                $options[$key] = trim((string)$value);
            }
        }

        $input = method_exists($request, 'getInput')
            ? (string)$request->getInput()
            : (string)file_get_contents('php://input');

        if (trim($input) !== '') {
            try {
                $decoded = Json::decode($input);
            } catch (\Throwable) {
                $decoded = null;
            }

            if (is_array($decoded)) {
                $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : $decoded;
                foreach (['date_from', 'date_to', 'period_label', 'format', 'quiz_code', 'quiz_label'] as $key) {
                    if (isset($payload[$key]) && is_scalar($payload[$key])) {
                        $options[$key] = trim((string)$payload[$key]);
                    }
                }
            }
        }

        $options['format'] = in_array(($options['format'] ?? 'csv'), ['csv', 'xls'], true) ? ($options['format'] ?? 'csv') : 'csv';
        $quizCode = trim((string)($options['quiz_code'] ?? ''));
        $options['quiz_code'] = preg_match('/^[a-zA-Z0-9_-]+$/', $quizCode) === 1 ? $quizCode : '';

        return $options;
    }

    private function getCleanupModeFromRequest(string $mode): string
    {
        $mode = trim($mode);
        if ($mode !== '') {
            return $mode;
        }

        $requestMode = $this->getRequest()->getPost('mode');
        if (is_string($requestMode) && trim($requestMode) !== '') {
            return trim($requestMode);
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

        return is_array($decoded) ? trim((string)($decoded['mode'] ?? '')) : '';
    }

    private function getCleanupQuizCodeFromRequest(string $quizCode): string
    {
        $quizCode = trim($quizCode);
        if ($quizCode !== '') {
            return $quizCode;
        }

        $requestQuizCode = $this->getRequest()->getPost('quiz_code');
        if (is_scalar($requestQuizCode)) {
            $quizCode = trim((string)$requestQuizCode);
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

        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : $decoded;

        return is_array($payload) && is_scalar($payload['quiz_code'] ?? null)
            ? trim((string)$payload['quiz_code'])
            : '';
    }

    private function disabledIntegrationResult(string $error, string $message): array
    {
        return [
            'success' => false,
            'disabled' => true,
            'error' => $error,
            'message' => $message,
        ];
    }

    private function getTrackingPayloadFromRequest(): array
    {
        $request = $this->getRequest();

        $postPayload = $request->getPost('payload');
        if (is_array($postPayload)) {
            return $postPayload;
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

        $payload = $decoded['event'] ?? $decoded['payload'] ?? $decoded;

        return is_array($payload) ? $payload : [];
    }
}
