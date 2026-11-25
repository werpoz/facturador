<?php

namespace App\Models;

use App\Enums\TipoAfectacionIgv;
use App\Enums\TipoMovimientoStock;
use App\Enums\UnidadMedida;
use App\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use RegistraBitacora;

    /** El stock cambia en cada venta: su historial es el kardex, no la bitácora */
    protected array $bitacoraIgnorar = ['stock'];

    protected static function booted(): void
    {
        // El stock con el que nace un producto también queda en el kardex
        static::created(function (Producto $p) {
            if ($p->controla_stock && (float) $p->stock !== 0.0) {
                MovimientoStock::create([
                    'empresa_id' => $p->empresa_id,
                    'producto_id' => $p->id,
                    'tipo' => TipoMovimientoStock::Inicial,
                    'cantidad' => $p->stock,
                    'stock_resultante' => $p->stock,
                    'user_id' => auth()->id(),
                ]);
            }
        });

        // Borrarlo arrastraría su kardex y dejaría comprobantes sin producto: con historial, se desactiva
        static::deleting(function (Producto $p) {
            if ($p->tieneHistorial()) {
                throw new \LogicException("\"{$p->descripcion}\" tiene ventas o movimientos de stock: no se puede eliminar. Desactívalo.");
            }
        });

        // Después de creado, el stock solo cambia a través de StockService (que escribe el kardex)
        static::updating(function (Producto $p) {
            if ($p->isDirty('stock')) {
                $p->stock = $p->getOriginal('stock');
            }
        });
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class);
    }

    public function tieneHistorial(): bool
    {
        return $this->movimientos()->where('tipo', '!=', TipoMovimientoStock::Inicial)->exists()
            || ComprobanteItem::query()->where('producto_id', $this->id)->exists();
    }

    public function estaAgotado(): bool
    {
        return $this->controla_stock && (float) $this->stock <= 0;
    }

    public function stockBajo(): bool
    {
        return $this->controla_stock && $this->stock_minimo !== null && (float) $this->stock <= (float) $this->stock_minimo;
    }

    protected $fillable = [
        'empresa_id', 'codigo', 'descripcion', 'unidad_medida',
        'precio_unitario', 'tipo_afectacion_igv', 'activo',
        'controla_stock', 'stock', 'stock_minimo',
    ];

    protected function casts(): array
    {
        return [
            'unidad_medida' => UnidadMedida::class,
            'tipo_afectacion_igv' => TipoAfectacionIgv::class,
            'precio_unitario' => 'decimal:2',
            'activo' => 'boolean',
            'controla_stock' => 'boolean',
            'stock' => 'decimal:4',
            'stock_minimo' => 'decimal:4',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function etiquetaBitacora(): string
    {
        return "producto {$this->descripcion}";
    }
}
