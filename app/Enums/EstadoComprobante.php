<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EstadoComprobante: string implements HasColor, HasIcon, HasLabel
{
    case Borrador = 'borrador';
    case Pendiente = 'pendiente';     // en cola para enviar al PSE
    case Aceptado = 'aceptado';
    case Observado = 'observado';     // aceptado con observaciones
    case Rechazado = 'rechazado';
    case Error = 'error';             // falló la comunicación (se reintenta)
    case Anulado = 'anulado';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Pendiente => 'warning',
            self::Aceptado => 'success',
            self::Observado => 'info',
            self::Rechazado, self::Error => 'danger',
            self::Anulado => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Borrador => 'heroicon-o-pencil-square',
            self::Pendiente => 'heroicon-o-clock',
            self::Aceptado => 'heroicon-o-check-circle',
            self::Observado => 'heroicon-o-exclamation-circle',
            self::Rechazado => 'heroicon-o-x-circle',
            self::Error => 'heroicon-o-exclamation-triangle',
            self::Anulado => 'heroicon-o-no-symbol',
        };
    }

    public function puedeEnviarse(): bool
    {
        return in_array($this, [self::Borrador, self::Error], true);
    }

    public function esEditable(): bool
    {
        return $this === self::Borrador;
    }
}
