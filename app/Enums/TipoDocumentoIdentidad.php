<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Catálogo 06 SUNAT */
enum TipoDocumentoIdentidad: string implements HasLabel
{
    case SinDocumento = '0';
    case Dni = '1';
    case CarnetExtranjeria = '4';
    case Ruc = '6';
    case Pasaporte = '7';
    case CedulaDiplomatica = 'A';

    public function getLabel(): string
    {
        return match ($this) {
            self::SinDocumento => 'Sin documento',
            self::Dni => 'DNI',
            self::CarnetExtranjeria => 'Carnet de extranjería',
            self::Ruc => 'RUC',
            self::Pasaporte => 'Pasaporte',
            self::CedulaDiplomatica => 'Cédula diplomática',
        };
    }

    public function longitud(): ?int
    {
        return match ($this) {
            self::Dni => 8,
            self::Ruc => 11,
            default => null,
        };
    }
}
