<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Kk\Quiz\Analytics\QuizEventTable;
use Kk\Quiz\Iblock\Installer;

final class QuizEventMaintenanceService
{
    public const DEFAULT_RETENTION_DAYS = 365;
    public const DELETE_BATCH_SIZE = 5000;


    public function getRetentionDays(): int
    {
        $value = (int)ModuleSettingsService::get('analytics_retention_days');

        return in_array($value, [0, 90, 180, 365], true) ? $value : self::DEFAULT_RETENTION_DAYS;
    }

    public function cleanupOldEvents(?int $retentionDays = null): array
    {
        $retentionDays = $retentionDays ?? $this->getRetentionDays();
        $retentionDays = max(0, (int)$retentionDays);
        $result = [
            'deleted' => 0,
            'retention_days' => $retentionDays,
            'success' => true,
            'errors' => [],
        ];

        if ($retentionDays === 0 || !$this->eventTableExists()) {
            return $result;
        }

        try {
            $cutoff = new DateTime(date('d.m.Y H:i:s', time() - ($retentionDays * 86400)), 'd.m.Y H:i:s');

            do {
                $ids = $this->selectEventIds(['<DATE_CREATE' => $cutoff], self::DELETE_BATCH_SIZE);
                if ($ids === []) {
                    break;
                }

                $result['deleted'] += $this->deleteEventIds($ids);
            } while (count($ids) >= self::DELETE_BATCH_SIZE);
        } catch (\Throwable $exception) {
            $result['success'] = false;
            $result['errors'][] = $exception->getMessage() !== '' ? $exception->getMessage() : 'CLEANUP_OLD_EVENTS_FAILED';
        }

        return $result;
    }

    public function cleanupOrphanQuizEvents(): array
    {
        $result = [
            'deleted' => 0,
            'quiz_codes' => [],
            'success' => true,
            'errors' => [],
        ];

        if (!$this->eventTableExists()) {
            return $result;
        }

        try {
            $quizCodes = $this->getOrphanQuizCodes();
            $result['quiz_codes'] = $quizCodes;

            foreach ($quizCodes as $quizCode) {
                do {
                    $ids = $this->selectEventIds(['=QUIZ_CODE' => $quizCode], self::DELETE_BATCH_SIZE);
                    if ($ids === []) {
                        break;
                    }

                    $result['deleted'] += $this->deleteEventIds($ids);
                } while (count($ids) >= self::DELETE_BATCH_SIZE);
            }
        } catch (\Throwable $exception) {
            $result['success'] = false;
            if ($exception->getMessage() === 'QUIZ_IBLOCK_NOT_AVAILABLE') {
                $result['deleted'] = 0;
                $result['quiz_codes'] = [];
                $result['errors'][] = 'QUIZ_IBLOCK_NOT_AVAILABLE';
            } else {
                $result['errors'][] = $exception->getMessage() !== '' ? $exception->getMessage() : 'CLEANUP_ORPHAN_EVENTS_FAILED';
            }
        }

        return $result;
    }

    public function cleanupAllEvents(?string $quizCode = null): array
    {
        $quizCode = $this->normalizeOptionalQuizCode($quizCode);
        $result = ['success' => true, 'deleted' => 0, 'quiz_code' => $quizCode ?? '', 'errors' => []];
        if (!$this->eventTableExists()) {
            return $result;
        }

        try {
            $filter = $quizCode !== null ? ['=QUIZ_CODE' => $quizCode] : [];
            do {
                $ids = $this->selectEventIds($filter, self::DELETE_BATCH_SIZE);
                if ($ids === []) {
                    break;
                }
                $result['deleted'] += $this->deleteEventIds($ids);
            } while (count($ids) >= self::DELETE_BATCH_SIZE);
        } catch (\Throwable $exception) {
            $result['success'] = false;
            $result['errors'][] = $exception->getMessage() ?: 'CLEANUP_ALL_EVENTS_FAILED';
        }

        return $result;
    }

    public function cleanupLeadElements(?string $quizCode = null): array
    {
        $quizCode = $this->normalizeOptionalQuizCode($quizCode);
        $result = ['success' => true, 'deleted' => 0, 'quiz_code' => $quizCode ?? '', 'errors' => []];

        if (!Loader::includeModule('iblock')) {
            return array_merge($result, ['success' => false, 'errors' => ['LEADS_IBLOCK_NOT_FOUND']]);
        }

        $iblock = \CIBlock::GetList([], [
            'TYPE' => Installer::IBLOCK_TYPE_ID,
            'CODE' => Installer::LEADS_IBLOCK_CODE,
        ])->Fetch();
        $iblockId = is_array($iblock) ? (int)($iblock['ID'] ?? 0) : 0;
        if ($iblockId <= 0) {
            return array_merge($result, ['success' => false, 'errors' => ['LEADS_IBLOCK_NOT_FOUND']]);
        }

        try {
            $filter = ['IBLOCK_ID' => $iblockId];
            if ($quizCode !== null) {
                $filter['=PROPERTY_KK_LEAD_QUIZ_CODE'] = $quizCode;
            }

            do {
                $ids = [];
                $elements = \CIBlockElement::GetList(['ID' => 'ASC'], $filter, false, ['nTopCount' => self::DELETE_BATCH_SIZE], ['ID']);
                while ($element = $elements->Fetch()) {
                    $id = (int)($element['ID'] ?? 0);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
                if ($ids === []) {
                    break;
                }

                $deletedInBatch = 0;
                foreach ($ids as $id) {
                    if (\CIBlockElement::Delete($id)) {
                        $deletedInBatch++;
                    } else {
                        $result['success'] = false;
                        $result['errors'][] = 'LEAD_DELETE_FAILED:' . $id;
                    }
                }
                $result['deleted'] += $deletedInBatch;
                if ($deletedInBatch === 0) {
                    break;
                }
            } while (count($ids) >= self::DELETE_BATCH_SIZE);
        } catch (\Throwable $exception) {
            $result['success'] = false;
            $result['errors'][] = $exception->getMessage() ?: 'CLEANUP_LEADS_FAILED';
        }

        $result['errors'] = array_values(array_unique($result['errors']));

        return $result;
    }

    public function cleanupFull(?string $quizCode = null): array
    {
        $events = $this->cleanupAllEvents($quizCode);
        $leads = $this->cleanupLeadElements($quizCode);
        $eventsDeleted = (int)($events['deleted'] ?? 0);
        $leadsDeleted = (int)($leads['deleted'] ?? 0);

        return [
            'success' => ($events['success'] ?? false) === true && ($leads['success'] ?? false) === true,
            'events' => $events,
            'leads' => $leads,
            'deleted' => ['events' => $eventsDeleted, 'leads' => $leadsDeleted, 'total' => $eventsDeleted + $leadsDeleted],
            'quiz_code' => $events['quiz_code'] ?? '',
            'errors' => array_values(array_unique(array_merge((array)($events['errors'] ?? []), (array)($leads['errors'] ?? [])))),
        ];
    }

    public function getOrphanQuizCodes(): array
    {
        if (!$this->eventTableExists()) {
            return [];
        }

        $eventQuizCodes = $this->getEventQuizCodes();
        if ($eventQuizCodes === []) {
            return [];
        }

        $existingQuizCodes = $this->getExistingQuizCodes($eventQuizCodes);
        $orphanCodes = array_values(array_diff($eventQuizCodes, $existingQuizCodes));
        sort($orphanCodes, SORT_STRING);

        return $orphanCodes;
    }

    public static function runCleanupAgent(): string
    {
        try {
            $service = new self();
            $service->cleanupOldEvents();
            $service->cleanupOrphanQuizEvents();
        } catch (\Throwable) {
        }

        return '\\Kk\\Quiz\\Service\\QuizEventMaintenanceService::runCleanupAgent();';
    }

    private function getEventQuizCodes(): array
    {
        $codes = [];
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $tableName = $helper->quote(QuizEventTable::getTableName());

        $rows = $connection->query('SELECT DISTINCT ' . $helper->quote('QUIZ_CODE') . ' FROM ' . $tableName . ' WHERE ' . $helper->quote('QUIZ_CODE') . " <> ''");
        while ($row = $rows->fetch()) {
            $code = trim((string)($row['QUIZ_CODE'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);

        return $codes;
    }

    private function normalizeOptionalQuizCode(?string $quizCode): ?string
    {
        $quizCode = trim((string)$quizCode);

        return $quizCode !== '' ? $quizCode : null;
    }

    private function getExistingQuizCodes(array $quizCodes): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('QUIZ_IBLOCK_NOT_AVAILABLE');
        }

        $iblock = \CIBlock::GetList([], [
            'TYPE' => Installer::IBLOCK_TYPE_ID,
            'CODE' => Installer::QUIZZES_IBLOCK_CODE,
        ])->Fetch();

        if (!is_array($iblock) || (int)($iblock['ID'] ?? 0) <= 0) {
            throw new \RuntimeException('QUIZ_IBLOCK_NOT_AVAILABLE');
        }

        $existingCodes = [];
        $sections = \CIBlockSection::GetList(
            [],
            [
                'IBLOCK_ID' => (int)$iblock['ID'],
                '=CODE' => $quizCodes,
            ],
            false,
            ['ID', 'CODE']
        );

        while ($section = $sections->Fetch()) {
            $code = trim((string)($section['CODE'] ?? ''));
            if ($code !== '') {
                $existingCodes[] = $code;
            }
        }

        return array_values(array_unique($existingCodes));
    }

    private function selectEventIds(array $filter, int $limit): array
    {
        $ids = [];
        $events = QuizEventTable::getList([
            'select' => ['ID'],
            'filter' => $filter,
            'order' => ['ID' => 'ASC'],
            'limit' => $limit,
        ]);

        while ($event = $events->fetch()) {
            $id = (int)($event['ID'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function deleteEventIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $connection->queryExecute(sprintf(
            'DELETE FROM %s WHERE %s IN (%s)',
            $helper->quote(QuizEventTable::getTableName()),
            $helper->quote('ID'),
            implode(',', $ids)
        ));

        return count($ids);
    }

    private function eventTableExists(): bool
    {
        try {
            return Application::getConnection()->isTableExists(QuizEventTable::getTableName());
        } catch (\Throwable) {
            return false;
        }
    }
}
