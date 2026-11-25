<?php

namespace App\Models;

use App\Enums\RegimenTributario;
use App\Models\Concerns\RegistraBitacora;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model implements HasName
{
    use RegistraBitacora;

    protected array $bitacoraOcultar = ['pse_token'];

    protected $fillable = [
        'ruc', 'regimen_tributario', 'bloquear_venta_sin_stock', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo',
        'departamento', 'provincia', 'distrito', 'telefono', 'email', 'logo',
        'pse_proveedor', 'pse_entorno', 'pse_token',
    ];

    protected $hidden = ['pse_token'];

    protected function casts(): array
    {
        return [
            'pse_token' => 'encrypted',
            'regimen_tributario' => RegimenTributario::class,
            'bloquear_venta_sin_stock' => 'boolean',
        ];
    }

    public function getFilamentName(): string
    {
        return $this->nombre_comercial ?: $this->razon_social;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('rol')->withTimestamps();
    }

    public function series(): HasMany
    {
        return $this->hasMany(Serie::class);
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (Empresa $e) {
            if ($e->comprobantes()->exists() || $e->ventas()->exists()) {
                throw new \LogicException('Una empresa con comprobantes o ventas no se puede eliminar.');
            }
        });
    }

    public function etiquetaBitacora(): string
    {
        return "los datos de la empresa {$this->ruc}";
    }

    public function movimientosStock(): HasMany
    {
        return $this->hasMany(MovimientoStock::class);
    }

    public function membresias(): HasMany
    {
        return $this->hasMany(Membresia::class);
    }

    public function bitacora(): HasMany
    {
        return $this->hasMany(Bitacora::class);
    }

    /** true si la empresa usa el proveedor de pruebas (nada se envía a SUNAT) */
    public function usaSimulador(): bool
    {
        return ($this->pse_proveedor ?: config('facturacion.proveedor_defecto')) === 'fake';
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function permiteFacturas(): bool
    {
        return ($this->regimen_tributario ?? RegimenTributario::Mype)->permiteFacturas();
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class);
    }
}
