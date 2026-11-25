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

    /** Un kilo o un metro se pueden vender por partes; una unidad, una caja o una docena, no. */
    public function permiteFracciones(): bool
    {
        return in_array($this, [self::Kilogramo, self::Litro, self::Metro, self::Galon, self::Hora, self::Servicio], true);
    }

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
