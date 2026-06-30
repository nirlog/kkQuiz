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

        $element = new \CIBlockElement();
        $id = (int)$element->Add([
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'NAME' => $this->buildName($lead),
            'PROPERTY_VALUES' => $properties,
        ]);

        if ($id <= 0) {
            throw new \RuntimeException((string)$element->LAST_ERROR ?: 'Не удалось сохранить заявку');
        }

        return $id;
    }

    private function getLeadsIblockId(): ?int
    {
        $iblock = \CIBlock::GetList([], [
            'TYPE' => Installer::IBLOCK_TYPE_ID,
            'CODE' => Installer::LEADS_IBLOCK_CODE,
            'ACTIVE' => 'Y',
        ])->Fetch();

        return is_array($iblock) ? (int)$iblock['ID'] : null;
    }

    private function getPropertyMap(): array
    {
        return [
            'quiz_section_id' => 'KK_LEAD_QUIZ_SECTION_ID',
            'quiz_code' => 'KK_LEAD_QUIZ_CODE',
            'quiz_name' => 'KK_LEAD_QUIZ_NAME',
            'result_id' => 'KK_LEAD_RESULT_ID',
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
            'email_sent' => 'KK_LEAD_EMAIL_SENT',
            'email_sent_at' => 'KK_LEAD_EMAIL_SENT_AT',
        ];
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
