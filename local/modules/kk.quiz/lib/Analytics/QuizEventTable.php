<?php

declare(strict_types=1);

namespace Kk\Quiz\Analytics;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;

final class QuizEventTable extends Entity\DataManager
{
    public static function getTableName(): string
    {
        return 'b_kk_quiz_events';
    }

    public static function getMap(): array
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
            ]),
            new Entity\DatetimeField('DATE_CREATE', [
                'default_value' => static fn () => new DateTime(),
            ]),
            new Entity\StringField('QUIZ_CODE', ['size' => 100]),
            new Entity\IntegerField('QUIZ_SECTION_ID', ['nullable' => true]),
            new Entity\StringField('RUN_ID', ['size' => 64]),
            new Entity\StringField('EVENT_TYPE', ['size' => 50]),
            new Entity\IntegerField('STEP_INDEX', ['nullable' => true]),
            new Entity\IntegerField('QUESTION_ID', ['nullable' => true]),
            new Entity\StringField('QUESTION_CODE', ['size' => 100]),
            new Entity\StringField('ANSWER_CODE', ['size' => 100]),
            new Entity\IntegerField('RESULT_ID', ['nullable' => true]),
            new Entity\StringField('RESULT_CODE', ['size' => 100]),
            new Entity\IntegerField('LEAD_ID', ['nullable' => true]),
        ];
    }
}
