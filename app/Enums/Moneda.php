<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Moneda: string implements HasLabel
{
    case Soles = 'PEN';
    case Dolares = 'USD';

    public function getLabel(): string
    {
        return match ($this) {
            self::Soles => 'Soles (S/)',
            self::Dolares => 'Dólares (US$)',
        };
    }

    public function simbolo(): string
    {
        return match ($this) {
            self::Soles => 'S/',
            self::Dolares => 'US$',
        };
    }
}
