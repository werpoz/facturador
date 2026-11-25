<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Estado COMERCIAL de la venta (se calcula a partir de sus comprobantes). */
enum EstadoVenta: string implements HasColor, HasIcon, HasLabel
{
    case Borrador = 'borrador';              // el comprobante aún no se emite
    case EnProceso = 'en_proceso';           // enviándose a SUNAT (o reintentando)
    case Confirmada = 'confirmada';          // comprobante aceptado
    case ConDevolucion = 'con_devolucion';   // tiene notas de crédito parciales
    case Anulada = 'anulada';                // acreditada por completo o dada de baja
    case Rechazada = 'rechazada';            // SUNAT rechazó el comprobante: hay que corregir

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::EnProceso => 'En proceso',
            self::Confirmada => 'Confirmada',
            self::ConDevolucion => 'Con devolución',
            self::Anulada => 'Anulada',
            self::Rechazada => 'Rechazada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador, self::Anulada => 'gray',
            self::EnProceso => 'warning',
            self::Confirmada => 'success',
            self::ConDevolucion => 'info',
            self::Rechazada => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Borrador => 'heroicon-o-pencil-square',
            self::EnProceso => 'heroicon-o-clock',
            self::Confirmada => 'heroicon-o-check-circle',
            self::ConDevolucion => 'heroicon-o-arrow-uturn-left',
            self::Anulada => 'heroicon-o-no-symbol',
            self::Rechazada => 'heroicon-o-x-circle',
        };
    }

    /** ¿La venta está vigente para efectos de stock, caja y reportes? */
    public function estaVigente(): bool
    {
        return in_array($this, [self::Confirmada, self::ConDevolucion], true);
    }
}
