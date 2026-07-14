<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Type\DateTime;
use Kk\Quiz\Analytics\LeadDeliveryLogTable;

final class LeadDeliveryLogService
{
    public function add(array $row): void
    {
        $leadId = (int)($row['lead_id'] ?? 0);
        $channel = $this->limit($row['channel'] ?? '', 50);

        if ($leadId <= 0 || $channel === '') {
            return;
        }

        try {
            LeadDeliveryLogTable::add([
                'DATE_CREATE' => new DateTime(),
                'LEAD_ID' => $leadId,
                'CHANNEL' => $channel,
                'EVENT' => $this->limit($row['event'] ?? '', 100),
                'SUCCESS' => !empty($row['success']) ? 'Y' : 'N',
                'SKIPPED' => !empty($row['skipped']) ? 'Y' : 'N',
                'STATUS' => $this->limit($row['status'] ?? '', 50),
                'ERROR' => $this->limit($row['error'] ?? '', 1000),
                'REQUEST_URL' => $this->limit($row['request_url'] ?? '', 500),
                'REQUEST_BODY' => $this->limit($row['request_body'] ?? '', 10000),
                'RESPONSE_BODY' => $this->limit($row['response_body'] ?? '', 10000),
                'DURATION_MS' => max(0, (int)($row['duration_ms'] ?? 0)),
            ]);
        } catch (\Throwable) {
        }
    }

    public function getByLeadId(int $leadId, int $limit = 20): array
    {
        if ($leadId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));

        try {
            $rows = [];
            $result = LeadDeliveryLogTable::getList([
                'filter' => ['=LEAD_ID' => $leadId],
                'order' => ['DATE_CREATE' => 'DESC', 'ID' => 'DESC'],
                'limit' => $limit,
            ]);

            while ($row = $result->fetch()) {
                $rows[] = $row;
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    private function limit(mixed $value, int $limit): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? $value;

        return function_exists('mb_substr') ? (string)mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
