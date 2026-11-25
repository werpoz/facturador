<?php

namespace App\Models;

use App\Enums\MedioPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    protected $fillable = ['empresa_id', 'venta_id', 'medio', 'monto', 'recibido', 'vuelto', 'user_id'];

    protected function casts(): array
    {
        return [
            'medio' => MedioPago::class,
            'monto' => 'decimal:2',
            'recibido' => 'decimal:2',
            'vuelto' => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
