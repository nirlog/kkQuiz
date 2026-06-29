<?php

declare(strict_types=1);

namespace Kk\Quiz\Helper;

final class Module
{
    public const ID = 'kk.quiz';

    public static function getModuleId(): string
    {
        return self::ID;
    }
}
