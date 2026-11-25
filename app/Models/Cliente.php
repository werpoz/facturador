<?php

namespace App\Models;

use App\Enums\TipoDocumentoIdentidad;
use App\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use RegistraBitacora;

    protected $fillable = [
        'empresa_id', 'tipo_documento', 'numero_documento', 'razon_social',
        'direccion', 'email', 'telefono',
    ];

    protected function casts(): array
    {
        return [
            'tipo_documento' => TipoDocumentoIdentidad::class,
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class);
    }

    public function getEtiquetaAttribute(): string
    {
        return "{$this->numero_documento} - {$this->razon_social}";
    }

    public function etiquetaBitacora(): string
    {
        return "cliente {$this->numero_documento} {$this->razon_social}";
    }
}
