<?php

namespace App\Models;

use App\Enums\EstadoVenta;
use App\Enums\Moneda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    protected $fillable = [
        'empresa_id', 'cliente_id', 'numero', 'fecha', 'moneda',
        'total', 'acreditado', 'debitado', 'neto', 'estado',
        'comprobante_principal_id', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoVenta::class,
            'moneda' => Moneda::class,
            'fecha' => 'date',
            'total' => 'decimal:2',
            'acreditado' => 'decimal:2',
            'debitado' => 'decimal:2',
            'neto' => 'decimal:2',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Todos los comprobantes de la venta: el original y sus notas de crédito/débito */
    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class)->orderBy('id');
    }

    /** La factura o boleta vigente de la venta */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_principal_id');
    }

    /** V-000123 */
    public function getCodigoAttribute(): string
    {
        return 'V-'.str_pad((string) $this->numero, 6, '0', STR_PAD_LEFT);
    }
}
