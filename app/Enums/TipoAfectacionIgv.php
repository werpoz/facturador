<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Catálogo 07 SUNAT (los más usados) */
enum TipoAfectacionIgv: string implements HasLabel
{
    case Gravado = '10';
    case GravadoGratuito = '11'; // Gravado – retiro por premio (gratuito)
    case Exonerado = '20';
    case Inafecto = '30';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gravado => 'Gravado - Operación onerosa',
            self::GravadoGratuito => 'Gravado - Gratuito (retiro por premio)',
            self::Exonerado => 'Exonerado',
            self::Inafecto => 'Inafecto',
        };
    }

    public function pagaIgv(): bool
    {
        return in_array($this, [self::Gravado, self::GravadoGratuito], true);
    }

    public function esGratuito(): bool
    {
        return $this === self::GravadoGratuito;
    }
}
