<?php

declare(strict_types=1);

namespace Smv\Domain;

trait EnumValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
