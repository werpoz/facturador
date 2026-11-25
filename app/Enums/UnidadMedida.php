<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Catálogo 03 SUNAT (subconjunto más usado) */
enum UnidadMedida: string implements HasLabel
{
    case Unidad = 'NIU';
    case Servicio = 'ZZ';
    case Kilogramo = 'KGM';
    case Litro = 'LTR';
    case Metro = 'MTR';
    case Caja = 'BX';
    case Docena = 'DZN';
    case Galon = 'GLL';
    case Hora = 'HUR';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unidad => 'Unidad (NIU)',
            self::Servicio => 'Servicio (ZZ)',
            self::Kilogramo => 'Kilogramo (KGM)',
            self::Litro => 'Litro (LTR)',
            self::Metro => 'Metro (MTR)',
            self::Caja => 'Caja (BX)',
            self::Docena => 'Docena (DZN)',
            self::Galon => 'Galón (GLL)',
            self::Hora => 'Hora (HUR)',
        };
    }
}
