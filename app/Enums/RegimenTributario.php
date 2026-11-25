<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RegimenTributario: string implements HasLabel
{
    case NuevoRus = 'nrus';
    case Especial = 'rer';
    case Mype = 'mype';
    case General = 'general';

    public function getLabel(): string
    {
        return match ($this) {
            self::NuevoRus => 'Nuevo RUS (solo boletas)',
            self::Especial => 'Régimen Especial (RER)',
            self::Mype => 'Régimen MYPE Tributario',
            self::General => 'Régimen General',
        };
    }

    /** El Nuevo RUS no puede emitir facturas (ni notas sobre facturas). */
    public function permiteFacturas(): bool
    {
        return $this !== self::NuevoRus;
    }
}
