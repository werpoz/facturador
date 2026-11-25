<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Registro de auditoría. Solo se inserta: no se puede modificar ni eliminar. */
class Bitacora extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bitacora';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cambios' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La bitácora no se puede modificar.'));
        static::deleting(fn () => throw new LogicException('La bitácora no se puede eliminar.'));
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function registrar(string $accion, string $descripcion, ?Model $modelo = null, ?array $cambios = null, ?int $empresaId = null): self
    {
        $user = auth()->user();

        $registro = new self([
            'empresa_id' => $empresaId
                ?? ($modelo instanceof Empresa ? $modelo->getKey() : $modelo?->getAttribute('empresa_id')),
            'user_id' => $user?->getKey(),
            'usuario_nombre' => $user?->name ?? 'Sistema',
            'accion' => $accion,
            'modelo_tipo' => $modelo ? class_basename($modelo) : null,
            'modelo_id' => $modelo?->getKey(),
            'descripcion' => mb_substr($descripcion, 0, 250),
            'cambios' => $cambios ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
        $registro->save();

        return $registro;
    }
}
