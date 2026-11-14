<?php

declare(strict_types=1);

namespace Smv\Domain;

enum Status: string
{
    use EnumValues;

    case Eingereicht = 'eingereicht';
    case InBearbeitung = 'in_bearbeitung';
    case Genehmigt = 'genehmigt';
    case Abgelehnt = 'abgelehnt';
    case Zurueckgestellt = 'zurueckgestellt';
}
