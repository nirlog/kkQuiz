<?php

declare(strict_types=1);

namespace Kk\Quiz\Analytics;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;

final class LeadDeliveryLogTable extends Entity\DataManager
{
    public static function getTableName(): string
    {
        return 'b_kk_quiz_lead_delivery_log';
    }

    public static function getMap(): array
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
            ]),
            new Entity\DatetimeField('DATE_CREATE', [
                'required' => true,
                'default_value' => static fn () => new DateTime(),
            ]),
            new Entity\IntegerField('LEAD_ID', ['required' => true]),
            new Entity\StringField('CHANNEL', ['required' => true, 'size' => 50]),
            new Entity\StringField('EVENT', ['size' => 100]),
            new Entity\StringField('SUCCESS', ['size' => 1]),
            new Entity\StringField('SKIPPED', ['size' => 1]),
            new Entity\StringField('STATUS', ['size' => 50]),
            new Entity\TextField('ERROR'),
            new Entity\StringField('REQUEST_URL', ['size' => 500]),
            new Entity\TextField('REQUEST_BODY'),
            new Entity\TextField('RESPONSE_BODY'),
            new Entity\IntegerField('DURATION_MS', ['nullable' => true]),
        ];
    }
}
