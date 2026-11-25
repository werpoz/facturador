<?php

namespace App\Models;

use App\Enums\EstadoComprobante;
use App\Enums\Moneda;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Exceptions\ComprobanteInmutable;
use App\Models\Concerns\RegistraBitacora;
use App\Observers\ComprobanteObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[ObservedBy(ComprobanteObserver::class)]
class Comprobante extends Model
{
    use RegistraBitacora;

    /** Cambios automáticos que no son acciones de una persona */
    protected array $bitacoraIgnorar = [
        'venta_id', 'op_gravadas', 'op_exoneradas', 'op_inafectas', 'op_gratuitas', 'igv',
        'sunat_codigo', 'sunat_mensaje', 'hash', 'enlace_pdf', 'enlace_xml', 'enlace_cdr',
        'intentos_envio', 'enviado_at', 'alertado_at', 'simulado', 'serie_id', 'emitido_at',
        'cliente_tipo_documento', 'cliente_numero_documento', 'cliente_razon_social', 'cliente_direccion',
    ];

    /**
     * Campos que SÍ pueden cambiar después de emitido: solo el resultado del envío.
     * Todo lo demás (cliente, fecha, montos, número...) queda congelado.
     */
    private const CAMPOS_DE_ENVIO = [
        'estado', 'simulado', 'sunat_codigo', 'sunat_mensaje', 'hash',
        'enlace_pdf', 'enlace_xml', 'enlace_cdr', 'intentos_envio', 'enviado_at', 'alertado_at',
        'venta_id', 'updated_at',
    ];

    private static bool $proteccionActiva = true;

    protected static function booted(): void
    {
        static::updating(function (Comprobante $c) {
            if (! self::$proteccionActiva) {
                return;
            }

            // Se pregunta a la BASE DE DATOS si ya tiene número: la copia en memoria puede ser vieja
            // (otra pestaña u otro usuario pudo emitirlo mientras tanto).
            $numero = static::query()->whereKey($c->getKey())->value('correlativo');
            if ($numero === null) {
                return; // los borradores (sin número) se editan libremente
            }

            $prohibidos = array_diff(array_keys($c->getDirty()), self::CAMPOS_DE_ENVIO);
            if ($prohibidos !== []) {
                throw new ComprobanteInmutable(
                    "El comprobante {$c->serie}-{$numero} ya fue emitido: no se puede cambiar ".implode(', ', $prohibidos).'.'
                );
            }
        });

        static::deleting(function (Comprobante $c) {
            if (self::$proteccionActiva && static::query()->whereKey($c->getKey())->value('correlativo') !== null) {
                throw new ComprobanteInmutable("El comprobante {$c->serie} ya fue emitido: no se puede eliminar.");
            }
        });
    }

    public static function estaProtegido(): bool
    {
        return self::$proteccionActiva;
    }

    /** Solo para tareas de mantenimiento controladas (p. ej. borrar los datos de prueba). */
    public static function sinProteccion(callable $tarea): mixed
    {
        self::$proteccionActiva = false;

        try {
            return $tarea();
        } finally {
            self::$proteccionActiva = true;
        }
    }

    public function etiquetaBitacora(): string
    {
        return mb_strtolower($this->tipo_comprobante->getLabel()).' '.$this->numero;
    }

    protected $fillable = [
        'empresa_id', 'venta_id', 'cliente_id', 'serie_id', 'tipo_comprobante', 'serie', 'correlativo',
        'fecha_emision', 'fecha_vencimiento', 'moneda', 'forma_pago',
        'comprobante_referencia_id', 'motivo_codigo', 'motivo_descripcion',
        'op_gravadas', 'op_exoneradas', 'op_inafectas', 'op_gratuitas', 'igv', 'total',
        'estado', 'sunat_codigo', 'sunat_mensaje', 'hash',
        'enlace_pdf', 'enlace_xml', 'enlace_cdr', 'intentos_envio', 'enviado_at',
        'observaciones', 'user_id', 'simulado', 'alertado_at', 'emitido_at',
        'cliente_tipo_documento', 'cliente_numero_documento', 'cliente_razon_social', 'cliente_direccion',
    ];

    protected function casts(): array
    {
        return [
            'tipo_comprobante' => TipoComprobante::class,
            'estado' => EstadoComprobante::class,
            'moneda' => Moneda::class,
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
            'enviado_at' => 'datetime',
            'emitido_at' => 'datetime',
            'cliente_tipo_documento' => TipoDocumentoIdentidad::class,
            'alertado_at' => 'datetime',
            'simulado' => 'boolean',
            'op_gravadas' => 'decimal:2',
            'op_exoneradas' => 'decimal:2',
            'op_inafectas' => 'decimal:2',
            'op_gratuitas' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function serieRelacion(): BelongsTo
    {
        return $this->belongsTo(Serie::class, 'serie_id');
    }

    public function referencia(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_referencia_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComprobanteItem::class)->orderBy('orden');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LogEnvio::class)->latest();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Datos del cliente para imprimir y enviar: los congelados al emitir.
     * (Un borrador, o un comprobante anterior a esta función, usa los actuales del cliente.)
     *
     * @return array{tipo: TipoDocumentoIdentidad, numero: string, nombre: string, direccion: ?string}
     */
    public function datosCliente(): array
    {
        if ($this->cliente_numero_documento !== null) {
            return [
                'tipo' => $this->cliente_tipo_documento,
                'numero' => $this->cliente_numero_documento,
                'nombre' => $this->cliente_razon_social,
                'direccion' => $this->cliente_direccion,
            ];
        }

        return [
            'tipo' => $this->cliente->tipo_documento,
            'numero' => $this->cliente->numero_documento,
            'nombre' => $this->cliente->razon_social,
            'direccion' => $this->cliente->direccion,
        ];
    }

    /** Último día para que el comprobante llegue a SUNAT */
    public function fechaLimiteEnvio(): Carbon
    {
        return $this->fecha_emision->copy()->addDays((int) config('facturacion.dias_maximos_envio', 3));
    }

    /** Días que quedan para enviarlo (negativo = ya venció). Solo importa si aún no fue aceptado. */
    public function diasParaVencer(): int
    {
        return (int) today()->diffInDays($this->fechaLimiteEnvio(), false);
    }

    public function estaPorAtender(): bool
    {
        return $this->correlativo !== null
            && in_array($this->estado, [EstadoComprobante::Pendiente, EstadoComprobante::Error], true);
    }

    /** F001-00000123 */
    public function getNumeroAttribute(): string
    {
        if (! $this->correlativo) {
            return "{$this->serie}-(borrador)";
        }

        return $this->serie.'-'.str_pad((string) $this->correlativo, 8, '0', STR_PAD_LEFT);
    }
}
