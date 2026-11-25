<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Rol de un usuario DENTRO de una empresa (un mismo usuario puede ser
 * administrador en una empresa y vendedor en otra).
 */
enum RolEmpresa: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Vendedor = 'vendedor';
    case Contador = 'contador';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Vendedor => 'Vendedor',
            self::Contador => 'Contador (solo lectura)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'primary',
            self::Vendedor => 'success',
            self::Contador => 'gray',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Admin => 'Acceso total: configuración, usuarios, anulaciones y devoluciones.',
            self::Vendedor => 'Emite facturas y boletas y registra clientes. No puede anular, devolver ni cambiar precios.',
            self::Contador => 'Solo consulta: ventas, comprobantes, reportes y bitácora. No puede emitir ni modificar.',
        };
    }

    /** @return list<Permiso> */
    public function permisos(): array
    {
        return match ($this) {
            self::Admin => Permiso::cases(),
            self::Vendedor => [
                Permiso::VerVentas,
                Permiso::EmitirVentas,
                Permiso::GestionarClientes,
                Permiso::VerProductos,
            ],
            self::Contador => [
                Permiso::VerVentas,
                Permiso::VerProductos,
                Permiso::VerBitacora,
            ],
        };
    }

    public function puede(Permiso $permiso): bool
    {
        return in_array($permiso, $this->permisos(), true);
    }
}
