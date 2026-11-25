<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogEnvio extends Model
{
    protected $table = 'logs_envio';

    protected $fillable = [
        'comprobante_id', 'accion', 'proveedor', 'http_status', 'request', 'response', 'exitoso',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'exitoso' => 'boolean',
        ];
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }
}
