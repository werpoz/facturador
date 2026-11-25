<?php

namespace App\Models\Concerns;

use App\Models\Bitacora;
use BackedEnum;
use DateTimeInterface;

/**
 * Deja rastro en la bitácora cada vez que el modelo se crea, modifica o elimina.
 *
 * Propiedades opcionales en el modelo:
 *   $bitacoraIgnorar = ['campo']  → cambios que no vale la pena registrar
 *   $bitacoraOcultar = ['campo']  → se registra que cambió, pero no el valor (secretos)
 * Método opcional: etiquetaBitacora(): string → cómo nombrar el registro ("factura F001-12")
 */
trait RegistraBitacora
{
    public static function bootRegistraBitacora(): void
    {
        static::created(fn ($m) => Bitacora::registrar('creó', 'Creó '.$m->nombreEnBitacora(), $m));

        static::updated(function ($m) {
            $cambios = $m->cambiosParaBitacora();
            if ($cambios !== []) {
                Bitacora::registrar('modificó', 'Modificó '.$m->nombreEnBitacora(), $m, $cambios);
            }
        });

        static::deleted(fn ($m) => Bitacora::registrar('eliminó', 'Eliminó '.$m->nombreEnBitacora(), $m));
    }

    public function nombreEnBitacora(): string
    {
        return method_exists($this, 'etiquetaBitacora')
            ? $this->etiquetaBitacora()
            : mb_strtolower(class_basename($this)).' #'.$this->getKey();
    }

    /** @return array<string, array{0:mixed, 1:mixed}> */
    public function cambiosParaBitacora(): array
    {
        $ignorar = array_merge(['created_at', 'updated_at', 'remember_token'], $this->bitacoraIgnorar ?? []);
        $ocultar = $this->bitacoraOcultar ?? [];
        $cambios = [];

        foreach ($this->getChanges() as $campo => $nuevo) {
            if (in_array($campo, $ignorar, true)) {
                continue;
            }

            $cambios[$campo] = in_array($campo, $ocultar, true)
                ? ['•••', '••• (cambiado)']
                : [$this->valorBitacora($this->getRawOriginal($campo)), $this->valorBitacora($nuevo)];
        }

        return $cambios;
    }

    private function valorBitacora(mixed $valor): mixed
    {
        return match (true) {
            $valor instanceof BackedEnum => $valor->value,
            $valor instanceof DateTimeInterface => $valor->format('Y-m-d H:i:s'),
            default => $valor,
        };
    }
}
