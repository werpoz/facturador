<?php

namespace App\Models;

use App\Enums\TipoAfectacionIgv;
use App\Enums\UnidadMedida;
use App\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Producto extends Model
{
    use RegistraBitacora;

    protected $fillable = [
        'empresa_id', 'codigo', 'descripcion', 'unidad_medida',
        'precio_unitario', 'tipo_afectacion_igv', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'unidad_medida' => UnidadMedida::class,
            'tipo_afectacion_igv' => TipoAfectacionIgv::class,
            'precio_unitario' => 'decimal:2',
            'activo' => 'boolean',
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
