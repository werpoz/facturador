<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TipoMovimientoStock: string implements HasColor, HasLabel
{
    case Inicial = 'inicial';
    case Ingreso = 'ingreso';         // llegó mercadería
    case Venta = 'venta';
    case Devolucion = 'devolucion';   // el cliente devolvió (nota de crédito)
    case Ajuste = 'ajuste';           // conteo físico: se corrige al número real
    case Merma = 'merma';             // pérdida, daño, vencimiento, robo
    case Reversion = 'reversion';     // SUNAT rechazó el comprobante: se deshace su movimiento

    public function getLabel(): string
    {
        return match ($this) {
            self::Inicial => 'Stock inicial',
            self::Ingreso => 'Ingreso de mercadería',
            self::Venta => 'Venta',
            self::Devolucion => 'Devolución de cliente',
            self::Ajuste => 'Ajuste por conteo',
            self::Merma => 'Merma o pérdida',
            self::Reversion => 'Reversión (comprobante rechazado)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inicial, self::Ajuste => 'gray',
            self::Ingreso, self::Devolucion => 'success',
            self::Venta => 'primary',
            self::Merma => 'danger',
            self::Reversion => 'warning',
        };
    }

    /** Movimientos que una persona puede registrar a mano desde la pantalla de productos */
    public static function manuales(): array
    {
        return [self::Ingreso, self::Ajuste, self::Merma];
    }
}
