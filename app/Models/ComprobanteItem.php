<?php

namespace App\Models;

use App\Enums\TipoAfectacionIgv;
use App\Enums\UnidadMedida;
use App\Exceptions\ComprobanteInmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComprobanteItem extends Model
{
    /** Los ítems de un comprobante emitido tampoco se pueden tocar. */
    protected static function booted(): void
    {
        $proteger = function (ComprobanteItem $item) {
            if (! Comprobante::estaProtegido() || ($item->exists && ! $item->isDirty() && ! $item->wasRecentlyCreated)) {
                return;
            }

            $numero = Comprobante::query()->whereKey($item->comprobante_id)->value('correlativo');
            if ($numero !== null) {
                throw new ComprobanteInmutable('El comprobante ya fue emitido: sus ítems no se pueden modificar.');
            }
        };

        static::saving($proteger);
        static::deleting(function (ComprobanteItem $item) {
            if (Comprobante::estaProtegido()
                && Comprobante::query()->whereKey($item->comprobante_id)->value('correlativo') !== null) {
                throw new ComprobanteInmutable('El comprobante ya fue emitido: sus ítems no se pueden eliminar.');
            }
        });
    }

    protected $fillable = [
        'comprobante_id', 'producto_id', 'codigo', 'descripcion', 'unidad_medida',
        'cantidad', 'precio_unitario', 'valor_unitario', 'tipo_afectacion_igv',
        'valor_venta', 'igv', 'total', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'unidad_medida' => UnidadMedida::class,
            'tipo_afectacion_igv' => TipoAfectacionIgv::class,
            'cantidad' => 'decimal:4',
            'precio_unitario' => 'decimal:4',
            'valor_unitario' => 'decimal:4',
            'valor_venta' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
