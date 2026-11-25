<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Str;

/**
 * En español solo va en mayúscula la primera letra de un título:
 * "Productos y servicios", "Crear cliente" (no "Productos Y Servicios", "Crear Cliente").
 */
trait EtiquetasEnEspanol
{
    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
