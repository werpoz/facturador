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

        // Rastro de auditoría: qué se agregó, cambió o quitó en un borrador que ya existía.
        // (Los ítems con los que NACE un comprobante no se registran uno por uno: ya queda el "creó".)
        static::created(fn (ComprobanteItem $i) => $i->registrarEnBitacora('agregó', 'Agregó'));
        static::deleted(fn (ComprobanteItem $i) => $i->registrarEnBitacora('quitó', 'Quitó'));
        static::updated(function (ComprobanteItem $i) {
            $cambios = [];
            foreach (['producto_id', 'descripcion', 'cantidad', 'precio_unitario', 'tipo_afectacion_igv'] as $campo) {
                if ($i->wasChanged($campo)) {
                    $antes = $i->getRawOriginal($campo);
                    $despues = $i->getAttributes()[$campo];
                    // "1.0000" y "1" son el mismo número: no es un cambio
                    if (is_numeric($antes) && is_numeric($despues) && (float) $antes === (float) $despues) {
                        continue;
                    }
                    $cambios[$campo] = [$antes, $despues instanceof \BackedEnum ? $despues->value : $despues];
                }
            }
            if ($cambios !== []) {
                $i->registrarEnBitacora('modificó', 'Modificó', $cambios);
            }
        });
        static::deleting(function (ComprobanteItem $item) {
            if (Comprobante::estaProtegido()
                && Comprobante::query()->whereKey($item->comprobante_id)->value('correlativo') !== null) {
                throw new ComprobanteInmutable('El comprobante ya fue emitido: sus ítems no se pueden eliminar.');
            }
        });
    }

    protected $fillable = [
        'comprobante_id', 'producto_id', 'codigo', 'descripcion', 'unidad_medida',
        'cantidad', 'precio_unitario', 'valor_unitario', 'valor_referencial', 'tipo_afectacion_igv',
        'valor_venta', 'igv', 'total', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'unidad_medida' => UnidadMedida::class,
            'tipo_afectacion_igv' => TipoAfectacionIgv::class,
            'cantidad' => 'decimal:4',
            'precio_unitario' => 'decimal:4',
            'valor_unitario' => 'decimal:10',
            'valor_referencial' => 'decimal:10',
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

    private function registrarEnBitacora(string $accion, string $verbo, ?array $cambios = null): void
    {
        if (Comprobante::esRecienCreado($this->comprobante_id) || ! Comprobante::estaProtegido()) {
            return;
        }

        $comprobante = Comprobante::query()->find($this->comprobante_id);
        if (! $comprobante) {
            return; // se está eliminando el comprobante entero: ya queda su propio registro
        }

        Bitacora::registrar(
            $accion,
            "{$verbo} el ítem \"{$this->descripcion}\" (".rtrim(rtrim((string) $this->cantidad, '0'), '.').' × '.$this->precio_unitario.') en '.$comprobante->etiquetaBitacora(),
            $comprobante,
            $cambios,
        );
    }
}
