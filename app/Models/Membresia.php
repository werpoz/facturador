<?php

namespace App\Models;

use App\Enums\RolEmpresa;
use App\Models\Concerns\RegistraBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un usuario dentro de una empresa, con su rol (tabla empresa_user). */
class Membresia extends Model
{
    use RegistraBitacora;

    protected $table = 'empresa_user';

    protected $fillable = ['empresa_id', 'user_id', 'rol'];

    protected function casts(): array
    {
        return ['rol' => RolEmpresa::class];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function etiquetaBitacora(): string
    {
        return "usuario {$this->user?->email} ({$this->rol?->getLabel()})";
    }
}
