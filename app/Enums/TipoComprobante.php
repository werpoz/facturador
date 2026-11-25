<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Catálogo 01 SUNAT */
enum TipoComprobante: string implements HasLabel
{
    case Factura = '01';
    case Boleta = '03';
    case NotaCredito = '07';
    case NotaDebito = '08';

    public function getLabel(): string
    {
        return match ($this) {
            self::Factura => 'Factura',
            self::Boleta => 'Boleta de venta',
            self::NotaCredito => 'Nota de crédito',
            self::NotaDebito => 'Nota de débito',
        };
    }

    /** Letra con la que debe empezar la serie */
    public function prefijosSerie(): array
    {
        return match ($this) {
            self::Factura => ['F'],
            self::Boleta => ['B'],
            self::NotaCredito, self::NotaDebito => ['F', 'B'],
        };
    }

    public function esNota(): bool
    {
        return in_array($this, [self::NotaCredito, self::NotaDebito], true);
    }
}
