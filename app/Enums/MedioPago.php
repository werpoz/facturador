<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum MedioPago: string implements HasIcon, HasLabel
{
    case Efectivo = 'efectivo';
    case Yape = 'yape';
    case Plin = 'plin';
    case Tarjeta = 'tarjeta';
    case Transferencia = 'transferencia';

    public function getLabel(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Yape => 'Yape',
            self::Plin => 'Plin',
            self::Tarjeta => 'Tarjeta',
            self::Transferencia => 'Transferencia',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Efectivo => 'heroicon-o-banknotes',
            self::Yape, self::Plin => 'heroicon-o-device-phone-mobile',
            self::Tarjeta => 'heroicon-o-credit-card',
            self::Transferencia => 'heroicon-o-building-library',
        };
    }

    /** Solo en efectivo se pide el monto recibido y se calcula el vuelto */
    public function calculaVuelto(): bool
    {
        return $this === self::Efectivo;
    }
}
