<?php

declare(strict_types=1);

namespace Smv\Domain;

enum NotifyType: string
{
    use EnumValues;

    case Lerngruppenrat = 'lerngruppenrat';
    case Texter = 'texter';
}
