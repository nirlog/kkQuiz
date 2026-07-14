<?php

declare(strict_types=1);

namespace Kk\Quiz\Repository;

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

final class LeadRepository
{
    public function add(array $lead): int
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock не подключен');
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            throw new \RuntimeException('Инфоблок заявок kk_quiz_leads не найден');
        }

        $properties = [];
        foreach ($this->getPropertyMap() as $leadKey => $propertyCode) {
            if (array_key_exists($leadKey, $lead)) {
                $properties[$propertyCode] = $lead[$leadKey];
            }
        }

        if (isset($properties['KK_LEAD_STATUS'])) {
            $properties['KK_LEAD_STATUS'] = $this->getEnumId(
                $iblockId,
                'KK_LEAD_STATUS',
                (string)$properties['KK_LEAD_STATUS']
            ) ?? $properties['KK_LEAD_STATUS'];
        }

        if (isset($properties['KK_LEAD_TELEGRAM_SENT'])) {
            $properties['KK_LEAD_TELEGRAM_SENT'] = $this->getEnumId(
                $iblockId,
                'KK_LEAD_TELEGRAM_SENT',
                (string)$properties['KK_LEAD_TELEGRAM_SENT']
            ) ?? $properties['KK_LEAD_TELEGRAM_SENT'];
        }

        if (isset($properties['KK_LEAD_WEBHOOK_SENT'])) {
            $properties['KK_LEAD_WEBHOOK_SENT'] = $this->getEnumId(
                $iblockId,
                'KK_LEAD_WEBHOOK_SENT',
                (string)$properties['KK_LEAD_WEBHOOK_SENT']
            ) ?? $properties['KK_LEAD_WEBHOOK_SENT'];
        }

        if (isset($properties['KK_LEAD_BITRIX24_SENT'])) {
            $properties['KK_LEAD_BITRIX24_SENT'] = $this->getEnumId(
                $iblockId,
                'KK_LEAD_BITRIX24_SENT',
                (string)$properties['KK_LEAD_BITRIX24_SENT']
            ) ?? $properties['KK_LEAD_BITRIX24_SENT'];
        }

        $element = new \CIBlockElement();
        $id = (int)$element->Add([
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'NAME' => $this->buildName($lead),
            'DETAIL_TEXT' => (string)($lead['detail_text'] ?? ''),
            'DETAIL_TEXT_TYPE' => 'text',
            'PROPERTY_VALUES' => $properties,
        ]);

        if ($id <= 0) {
            throw new \RuntimeException((string)$element->LAST_ERROR ?: 'Не удалось сохранить заявку');
        }

        return $id;
    }


    public function markEmailSent(int $leadId): void
    {
        if ($leadId <= 0 || !Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            return;
        }

        \CIBlockElement::SetPropertyValuesEx($leadId, $iblockId, [
            'KK_LEAD_EMAIL_SENT' => $this->getEnumId($iblockId, 'KK_LEAD_EMAIL_SENT', 'Y') ?? 'Y',
            'KK_LEAD_EMAIL_SENT_AT' => date('d.m.Y H:i:s'),
        ]);
    }


    public function markTelegramSent(int $leadId): void
    {
        if ($leadId <= 0 || !Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            return;
        }

        \CIBlockElement::SetPropertyValuesEx($leadId, $iblockId, [
            'KK_LEAD_TELEGRAM_SENT' => $this->getEnumId($iblockId, 'KK_LEAD_TELEGRAM_SENT', 'Y') ?? 'Y',
            'KK_LEAD_TELEGRAM_SENT_AT' => date('d.m.Y H:i:s'),
            'KK_LEAD_TELEGRAM_ERROR' => '',
        ]);
    }

    public function markTelegramFailed(int $leadId, string $error): void
    {
        if ($leadId <= 0 || !Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            return;
        }

        $error = trim(strip_tags($error));
        $error = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error;
        $error = mb_substr(trim($error), 0, 1000);

        \CIBlockElement::SetPropertyValuesEx($leadId, $iblockId, [
            'KK_LEAD_TELEGRAM_SENT' => $this->getEnumId($iblockId, 'KK_LEAD_TELEGRAM_SENT', 'N') ?? 'N',
            'KK_LEAD_TELEGRAM_SENT_AT' => '',
            'KK_LEAD_TELEGRAM_ERROR' => $error,
        ]);
    }

    public function markWebhookResult(int $leadId, array $result): void
    {
        if ($leadId <= 0 || !Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            return;
        }

        $skipped = (bool)($result['skipped'] ?? false);
        $success = (bool)($result['success'] ?? false) && !$skipped;
        $status = trim((string)($result['status_label'] ?? ''));
        if ($status === '') {
            $status = $skipped
                ? 'skipped'
                : ((int)($result['status'] ?? 0) > 0 ? 'HTTP_' . (int)$result['status'] : 'ERROR');
        }
        $error = $skipped
            ? (string)($result['reason'] ?? 'WEBHOOK_DISABLED')
            : ($success ? '' : (string)($result['error'] ?? 'WEBHOOK_SEND_FAILED'));

        $error = trim(strip_tags($error));
        $error = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error;
        $error = mb_substr(trim($error), 0, 1000);

        \CIBlockElement::SetPropertyValuesEx($leadId, $iblockId, [
            'KK_LEAD_WEBHOOK_SENT' => $this->getEnumId($iblockId, 'KK_LEAD_WEBHOOK_SENT', $success ? 'Y' : 'N') ?? ($success ? 'Y' : 'N'),
            'KK_LEAD_WEBHOOK_SENT_AT' => $success ? date('d.m.Y H:i:s') : '',
            'KK_LEAD_WEBHOOK_STATUS' => $status,
            'KK_LEAD_WEBHOOK_ERROR' => $error,
        ]);
    }


    public function markBitrix24Result(int $leadId, array $result): void
    {
        if ($leadId <= 0 || !Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            return;
        }

        $skipped = (bool)($result['skipped'] ?? false);
        $success = (bool)($result['success'] ?? false) && !$skipped;
        $status = trim((string)($result['status_label'] ?? ''));
        if ($status === '') {
            $status = $skipped
                ? 'skipped'
                : ((int)($result['status'] ?? 0) > 0 ? 'HTTP_' . (int)$result['status'] : 'ERROR');
        }
        $error = $skipped
            ? (string)($result['reason'] ?? 'BITRIX24_DISABLED')
            : ($success ? '' : (string)($result['error'] ?? 'BITRIX24_SEND_FAILED'));

        $error = trim(strip_tags($error));
        $error = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error;
        $error = mb_substr(trim($error), 0, 1000);

        \CIBlockElement::SetPropertyValuesEx($leadId, $iblockId, [
            'KK_LEAD_BITRIX24_SENT' => $this->getEnumId($iblockId, 'KK_LEAD_BITRIX24_SENT', $success ? 'Y' : 'N') ?? ($success ? 'Y' : 'N'),
            'KK_LEAD_BITRIX24_SENT_AT' => $success ? date('d.m.Y H:i:s') : '',
            'KK_LEAD_BITRIX24_STATUS' => $status,
            'KK_LEAD_BITRIX24_ERROR' => $error,
            'KK_LEAD_BITRIX24_LEAD_ID' => $success ? (string)($result['external_id'] ?? '') : '',
        ]);
    }


    public function getLeadDataById(int $leadId): ?array
    {
        if ($leadId <= 0 || !Loader::includeModule('iblock')) {
            return null;
        }

        $iblockId = $this->getLeadsIblockId();
        if ($iblockId === null) {
            return null;
        }

        $element = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $leadId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'DATE_CREATE', 'DETAIL_TEXT']
        )->Fetch();

        if (!is_array($element)) {
            return null;
        }

        $lead = [
            'id' => (int)$element['ID'],
            'name' => (string)($element['NAME'] ?? ''),
            'created_at' => (string)($element['DATE_CREATE'] ?? ''),
            'detail_text' => (string)($element['DETAIL_TEXT'] ?? ''),
        ];

        $propertyMap = $this->getPropertyMap();
        foreach (array_keys($propertyMap) as $leadKey) {
            $lead[$leadKey] = '';
        }

        $propertyCodeToLeadKey = array_flip($propertyMap);
        $propertyResult = \CIBlockElement::GetProperty(
            $iblockId,
            $leadId,
            ['sort' => 'asc', 'id' => 'asc'],
            []
        );

        while ($property = $propertyResult->Fetch()) {
            $code = (string)($property['CODE'] ?? '');
            if (!isset($propertyCodeToLeadKey[$code])) {
                continue;
            }

            $lead[$propertyCodeToLeadKey[$code]] = $this->getReadablePropertyValue($property);
        }

        return $lead;
    }

    public function getLeadsIblockId(): ?int
    {
        $iblock = \CIBlock::GetList([], [
            'TYPE' => Installer::IBLOCK_TYPE_ID,
            'CODE' => Installer::LEADS_IBLOCK_CODE,
            'ACTIVE' => 'Y',
        ])->Fetch();

        return is_array($iblock) ? (int)$iblock['ID'] : null;
    }


    private function getReadablePropertyValue(array $property): string
    {
        $propertyType = (string)($property['PROPERTY_TYPE'] ?? '');

        if ($propertyType === 'L') {
            foreach (['VALUE_XML_ID', 'VALUE_ENUM', 'VALUE'] as $key) {
                if (!array_key_exists($key, $property)) {
                    continue;
                }

                $value = $property[$key];
                if (is_array($value)) {
                    $value = reset($value);
                }

                if (is_scalar($value)) {
                    return (string)$value;
                }
            }

            return '';
        }

        $value = $property['VALUE'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private function getPropertyMap(): array
    {
        return [
            'quiz_section_id' => 'KK_LEAD_QUIZ_SECTION_ID',
            'quiz_code' => 'KK_LEAD_QUIZ_CODE',
            'quiz_name' => 'KK_LEAD_QUIZ_NAME',
            'result_id' => 'KK_LEAD_RESULT_ID',
            'status' => 'KK_LEAD_STATUS',
            'manager_note' => 'KK_LEAD_MANAGER_NOTE',
            'result_code' => 'KK_LEAD_RESULT_CODE',
            'result_title' => 'KK_LEAD_RESULT_TITLE',
            'client_name' => 'KK_LEAD_CLIENT_NAME',
            'client_phone' => 'KK_LEAD_CLIENT_PHONE',
            'client_email' => 'KK_LEAD_CLIENT_EMAIL',
            'client_messenger' => 'KK_LEAD_CLIENT_MESSENGER',
            'client_comment' => 'KK_LEAD_CLIENT_COMMENT',
            'page_url' => 'KK_LEAD_PAGE_URL',
            'referer' => 'KK_LEAD_REFERER',
            'utm_source' => 'KK_LEAD_UTM_SOURCE',
            'utm_medium' => 'KK_LEAD_UTM_MEDIUM',
            'utm_campaign' => 'KK_LEAD_UTM_CAMPAIGN',
            'utm_content' => 'KK_LEAD_UTM_CONTENT',
            'utm_term' => 'KK_LEAD_UTM_TERM',
            'user_agent' => 'KK_LEAD_USER_AGENT',
            'ip' => 'KK_LEAD_IP',
            'session_id' => 'KK_LEAD_SESSION_ID',
            'answers_data' => 'KK_LEAD_ANSWERS_DATA',
            'agreement_accepted' => 'KK_LEAD_AGREEMENT_ACCEPTED',
            'privacy_url' => 'KK_LEAD_PRIVACY_URL',
            'email_sent' => 'KK_LEAD_EMAIL_SENT',
            'email_sent_at' => 'KK_LEAD_EMAIL_SENT_AT',
            'telegram_sent' => 'KK_LEAD_TELEGRAM_SENT',
            'telegram_sent_at' => 'KK_LEAD_TELEGRAM_SENT_AT',
            'telegram_error' => 'KK_LEAD_TELEGRAM_ERROR',
            'webhook_sent' => 'KK_LEAD_WEBHOOK_SENT',
            'webhook_sent_at' => 'KK_LEAD_WEBHOOK_SENT_AT',
            'webhook_status' => 'KK_LEAD_WEBHOOK_STATUS',
            'webhook_error' => 'KK_LEAD_WEBHOOK_ERROR',
            'bitrix24_sent' => 'KK_LEAD_BITRIX24_SENT',
            'bitrix24_sent_at' => 'KK_LEAD_BITRIX24_SENT_AT',
            'bitrix24_status' => 'KK_LEAD_BITRIX24_STATUS',
            'bitrix24_error' => 'KK_LEAD_BITRIX24_ERROR',
            'bitrix24_lead_id' => 'KK_LEAD_BITRIX24_LEAD_ID',
        ];
    }


    private function getEnumId(int $iblockId, string $propertyCode, string $xmlId): ?int
    {
        $enum = \CIBlockPropertyEnum::GetList([], [
            'IBLOCK_ID' => $iblockId,
            'CODE' => $propertyCode,
            'XML_ID' => $xmlId,
        ])->Fetch();

        return is_array($enum) ? (int)$enum['ID'] : null;
    }

    private function buildName(array $lead): string
    {
        $parts = ['Заявка квиза'];
        if (!empty($lead['quiz_name'])) {
            $parts[] = (string)$lead['quiz_name'];
        }
        if (!empty($lead['client_phone'])) {
            $parts[] = (string)$lead['client_phone'];
        }

        return implode(' — ', $parts);
    }
}
