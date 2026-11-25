<?php

namespace App\Support;

use App\Enums\Permiso;
use App\Models\Empresa;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/** Punto único para preguntar "¿el usuario actual puede hacer esto en esta empresa?". */
class Permisos
{
    public static function puede(Permiso $permiso, ?Empresa $empresa = null): bool
    {
        $user = auth()->user();

        // Sin usuario = proceso del sistema (cola, tarea programada, consola)
        if (! $user) {
            return true;
        }

        $empresa ??= Filament::getTenant();

        return $empresa instanceof Empresa && $user->puede($permiso, $empresa);
    }

    public static function exigir(Permiso $permiso, ?Empresa $empresa = null, string $mensaje = 'No tienes permiso para realizar esta acción.'): void
    {
        if (! static::puede($permiso, $empresa)) {
            throw ValidationException::withMessages(['permiso' => $mensaje]);
        }
    }
}
