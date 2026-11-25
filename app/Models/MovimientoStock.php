<?php

namespace App\Models;

use App\Enums\TipoMovimientoStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Una línea del kardex. Solo se inserta: un error se corrige con otro movimiento, nunca editando. */
class MovimientoStock extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'movimientos_stock';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoStock::class,
            'cantidad' => 'decimal:4',
            'stock_resultante' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un movimiento de stock no se puede modificar.'));
        static::deleting(fn () => throw new LogicException('Un movimiento de stock no se puede eliminar.'));
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
