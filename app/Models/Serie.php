<?php

namespace App\Models;

use App\Enums\TipoComprobante;
use App\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Serie extends Model
{
    use RegistraBitacora;

    protected array $bitacoraIgnorar = ['correlativo'];

    protected $table = 'series';

    protected $fillable = ['empresa_id', 'tipo_comprobante', 'serie', 'correlativo', 'activo'];

    protected function casts(): array
    {
        return [
            'tipo_comprobante' => TipoComprobante::class,
            'correlativo' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * Reserva el siguiente correlativo de forma segura (sin saltos ni duplicados)
     * aunque dos cajeros emitan al mismo tiempo. Debe llamarse dentro de una transacción.
     */
    public function siguienteCorrelativo(): int
    {
        return DB::transaction(function () {
            $serie = static::query()->lockForUpdate()->findOrFail($this->id);
            $serie->increment('correlativo');

            return $serie->correlativo;
        });
    }

    public function etiquetaBitacora(): string
    {
        return "serie {$this->serie}";
    }
}
