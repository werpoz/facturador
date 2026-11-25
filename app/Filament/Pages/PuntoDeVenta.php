<?php

namespace App\Filament\Pages;

use App\Enums\MedioPago;
use App\Enums\Permiso;
use App\Enums\TipoAfectacionIgv;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Producto;
use App\Services\Facturacion\CalculadoraTotales;
use App\Services\Inventario\StockService;
use App\Services\Ventas\VentaRapidaService;
use App\Support\Permisos;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Punto de venta: pensado primero para celular. Tocar productos, cobrar y listo.
 * La boleta o factura se emite en el mismo paso.
 */
class PuntoDeVenta extends Page
{
    protected string $view = 'filament.pages.punto-de-venta';

    protected static ?string $slug = 'pos';

    protected static ?string $title = 'Punto de venta';

    protected static ?string $navigationLabel = 'Punto de venta';

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected Width|string|null $maxContentWidth = Width::Full;

    public string $busqueda = '';

    /** @var array<string, array{producto_id:int, descripcion:string, precio:string, cantidad:string, unidad:string, afectacion:string}> */
    public array $carrito = [];

    public string $tipo = '03';

    public ?int $clienteId = null;

    public string $buscarCliente = '';

    public string $nuevoClienteNombre = '';

    public string $medio = 'efectivo';

    public ?string $recibido = null;

    public ?int $ultimaVentaId = null;

    public static function canAccess(): bool
    {
        return Permisos::puede(Permiso::EmitirVentas);
    }

    public function mount(): void
    {
        $this->clienteId = $this->clientesVarios()?->id;
    }

    // ───────────────────────── Productos ─────────────────────────

    #[Computed]
    public function productos(): Collection
    {
        $q = trim($this->busqueda);

        return $this->catalogo()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('descripcion', 'like', "%{$q}%")
                ->orWhere('codigo', 'like', "%{$q}%")))
            ->orderBy('descripcion')
            ->limit(60)
            ->get();
    }

    private function catalogo()
    {
        return Producto::query()->where('empresa_id', Filament::getTenant()->id)->where('activo', true);
    }

    public function agregar(int $productoId): void
    {
        $producto = $this->catalogo()->find($productoId);
        if (! $producto) {
            return;
        }

        $clave = 'p'.$producto->id;

        if (isset($this->carrito[$clave])) {
            $this->incrementar($clave);

            return;
        }

        if (! $this->hayStockPara($producto->controla_stock ? (float) $producto->stock : null, 1, $producto->descripcion)) {
            return;
        }

        $this->carrito[$clave] = [
            // null = no lleva control de stock (servicios)
            'stock' => $producto->controla_stock ? (float) $producto->stock : null,
            'producto_id' => $producto->id,
            'descripcion' => $producto->descripcion,
            'precio' => number_format((float) $producto->precio_unitario, 2, '.', ''),
            'cantidad' => '1',
            'unidad' => $producto->unidad_medida->value,
            'afectacion' => $producto->tipo_afectacion_igv->value,
        ];
    }

    /** Enter en el buscador: código exacto (lector de código de barras) o el primer resultado. */
    public function agregarPrimero(): void
    {
        $q = trim($this->busqueda);
        if ($q === '') {
            return;
        }

        $producto = $this->catalogo()->where('codigo', $q)->first() ?? $this->productos->first();

        if (! $producto) {
            Notification::make()->warning()->title("No se encontró \"{$q}\"")->send();

            return;
        }

        $this->agregar($producto->id);
        $this->busqueda = '';
    }

    public function incrementar(string $clave): void
    {
        if (! isset($this->carrito[$clave])) {
            return;
        }

        $nueva = $this->numero($this->carrito[$clave]['cantidad']) + 1;

        if ($this->hayStockPara($this->carrito[$clave]['stock'] ?? null, $nueva, $this->carrito[$clave]['descripcion'])) {
            $this->carrito[$clave]['cantidad'] = $this->formato($nueva);
        }
    }

    /** Si la empresa no permite vender sin stock, avisa y no deja agregar más de lo que hay. */
    private function hayStockPara(?float $stock, float $cantidad, string $descripcion): bool
    {
        if ($stock === null || ! $this->bloqueaSinStock() || $cantidad <= $stock) {
            return true;
        }

        Notification::make()->warning()
            ->title($stock <= 0 ? "\"{$descripcion}\" está agotado" : 'Solo quedan '.StockService::fmt($stock)." de \"{$descripcion}\"")
            ->send();

        return false;
    }

    public function bloqueaSinStock(): bool
    {
        return (bool) Filament::getTenant()->bloquear_venta_sin_stock;
    }

    /** Líneas de la venta que piden más de lo que hay en el almacén */
    public function excedeStock(array $linea): bool
    {
        return ($linea['stock'] ?? null) !== null && $this->numero($linea['cantidad']) > $linea['stock'];
    }

    public function decrementar(string $clave): void
    {
        if (! isset($this->carrito[$clave])) {
            return;
        }

        $nueva = $this->numero($this->carrito[$clave]['cantidad']) - 1;

        if ($nueva <= 0) {
            unset($this->carrito[$clave]);
        } else {
            $this->carrito[$clave]['cantidad'] = $this->formato($nueva);
        }
    }

    public function quitar(string $clave): void
    {
        unset($this->carrito[$clave]);
    }

    public function vaciar(): void
    {
        $this->carrito = [];
        $this->recibido = null;
    }

    // ───────────────────────── Totales ─────────────────────────

    #[Computed]
    public function totales(): array
    {
        $calc = app(CalculadoraTotales::class);
        $items = [];

        foreach ($this->carrito as $linea) {
            $items[] = ['tipo_afectacion_igv' => $linea['afectacion']]
                + $calc->calcularItem((string) $this->numero($linea['cantidad']), $linea['precio'], $linea['afectacion']);
        }

        return $calc->calcularTotales($items);
    }

    public function importe(array $linea): string
    {
        if (TipoAfectacionIgv::from($linea['afectacion'])->esGratuito()) {
            return '0.00';
        }

        return app(CalculadoraTotales::class)
            ->calcularItem((string) $this->numero($linea['cantidad']), $linea['precio'], $linea['afectacion'])['total'];
    }

    #[Computed]
    public function unidades(): float
    {
        return array_sum(array_map(fn ($l) => $this->numero($l['cantidad']), $this->carrito));
    }

    #[Computed]
    public function vuelto(): ?float
    {
        if (! MedioPago::from($this->medio)->calculaVuelto() || blank($this->recibido)) {
            return null;
        }

        return round($this->numero($this->recibido) - (float) $this->totales['total'], 2);
    }

    /** Billetes con los que el cliente podría pagar (para tocar en vez de escribir) */
    #[Computed]
    public function billetes(): array
    {
        $total = (float) $this->totales['total'];
        if ($total <= 0) {
            return [];
        }

        $opciones = [number_format($total, 2, '.', '')];
        foreach ([10, 20, 50, 100, 200] as $billete) {
            $monto = ceil($total / $billete) * $billete;
            if ($monto > $total) {
                $opciones[] = number_format($monto, 2, '.', '');
            }
        }

        return array_slice(array_values(array_unique($opciones)), 0, 5);
    }

    // ───────────────────────── Cliente ─────────────────────────

    #[Computed]
    public function cliente(): ?Cliente
    {
        return $this->clienteId
            ? Cliente::query()->where('empresa_id', Filament::getTenant()->id)->find($this->clienteId)
            : null;
    }

    #[Computed]
    public function clientesEncontrados(): Collection
    {
        $q = trim($this->buscarCliente);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        return Cliente::query()
            ->where('empresa_id', Filament::getTenant()->id)
            ->where(fn ($w) => $w->where('numero_documento', 'like', "{$q}%")->orWhere('razon_social', 'like', "%{$q}%"))
            ->orderBy('razon_social')
            ->limit(5)
            ->get();
    }

    /** Si lo escrito es un DNI (8 dígitos) o un RUC (11) que aún no existe, se ofrece registrarlo ahí mismo. */
    #[Computed]
    public function documentoNuevo(): ?TipoDocumentoIdentidad
    {
        $q = trim($this->buscarCliente);

        $tipo = match (true) {
            (bool) preg_match('/^\d{8}$/', $q) => TipoDocumentoIdentidad::Dni,
            (bool) preg_match('/^(10|15|16|17|20)\d{9}$/', $q) => TipoDocumentoIdentidad::Ruc,
            default => null,
        };

        return $tipo && ! $this->clientesEncontrados->contains('numero_documento', $q) ? $tipo : null;
    }

    public function seleccionarCliente(int $id): void
    {
        if (! Cliente::query()->where('empresa_id', Filament::getTenant()->id)->whereKey($id)->exists()) {
            return;
        }

        $this->clienteId = $id;
        $this->buscarCliente = '';
        $this->nuevoClienteNombre = '';
        unset($this->cliente);

        // Con RUC lo usual es factura; sin RUC solo puede ser boleta
        if ($this->cliente?->tipo_documento === TipoDocumentoIdentidad::Ruc && $this->permiteFactura()) {
            $this->tipo = TipoComprobante::Factura->value;
        } elseif ($this->cliente?->tipo_documento !== TipoDocumentoIdentidad::Ruc) {
            $this->tipo = TipoComprobante::Boleta->value;
        }
    }

    public function registrarCliente(): void
    {
        $tipo = $this->documentoNuevo;
        $nombre = trim($this->nuevoClienteNombre);

        if (! $tipo || $nombre === '') {
            Notification::make()->warning()->title('Escribe el nombre o la razón social del cliente.')->send();

            return;
        }
        if (! Permisos::puede(Permiso::GestionarClientes)) {
            Notification::make()->danger()->title('No tienes permiso para registrar clientes.')->send();

            return;
        }

        // TODO: autocompletar el nombre con una consulta de RUC/DNI
        $cliente = Filament::getTenant()->clientes()->create([
            'tipo_documento' => $tipo,
            'numero_documento' => trim($this->buscarCliente),
            'razon_social' => mb_strtoupper($nombre),
        ]);

        $this->seleccionarCliente($cliente->id);
    }

    public function usarClientesVarios(): void
    {
        if ($varios = $this->clientesVarios()) {
            $this->seleccionarCliente($varios->id);
        }
    }

    private function clientesVarios(): ?Cliente
    {
        return Filament::getTenant()->clientes()
            ->where('tipo_documento', TipoDocumentoIdentidad::SinDocumento)
            ->orderBy('id')
            ->first();
    }

    public function permiteFactura(): bool
    {
        return Filament::getTenant()->permiteFacturas();
    }

    // ───────────────────────── Cobrar ─────────────────────────

    public function cobrar(VentaRapidaService $ventas): void
    {
        if (! $this->permiteFactura()) {
            $this->tipo = TipoComprobante::Boleta->value;
        }

        try {
            $comprobante = $ventas->registrar(
                empresa: Filament::getTenant(),
                tipo: TipoComprobante::from($this->tipo),
                clienteId: $this->clienteId,
                carrito: array_map(fn ($l) => ['producto_id' => $l['producto_id'], 'cantidad' => $this->numero($l['cantidad'])], array_values($this->carrito)),
                medio: MedioPago::from($this->medio),
                recibido: $this->recibido,
                usuario: auth()->user(),
                totalEsperado: $this->totales['total'],
            );
        } catch (ValidationException $e) {
            Notification::make()->danger()
                ->title('No se pudo registrar la venta')
                ->body(implode("\n", collect($e->errors())->flatten()->all()))
                ->duration(10000)
                ->send();

            $this->actualizarPrecios(); // por si el motivo fue un cambio de precio o de stock

            return;
        }

        $this->ultimaVentaId = $comprobante->id;
        $this->dispatch('venta-registrada');
    }

    /** Pone al día precio y stock de lo que hay en la venta, según el catálogo actual. */
    private function actualizarPrecios(): void
    {
        foreach ($this->carrito as $clave => $linea) {
            if ($p = $this->catalogo()->find($linea['producto_id'])) {
                $this->carrito[$clave]['precio'] = number_format((float) $p->precio_unitario, 2, '.', '');
                $this->carrito[$clave]['stock'] = $p->controla_stock ? (float) $p->stock : null;
            }
        }
        unset($this->totales);
    }

    #[Computed]
    public function ultimaVenta(): ?Comprobante
    {
        return $this->ultimaVentaId
            ? Comprobante::query()->with('venta.pagos')->where('empresa_id', Filament::getTenant()->id)->find($this->ultimaVentaId)
            : null;
    }

    public function nuevaVenta(): void
    {
        $this->vaciar();
        $this->ultimaVentaId = null;
        $this->busqueda = '';
        $this->medio = MedioPago::Efectivo->value;
        $this->tipo = TipoComprobante::Boleta->value;
        $this->usarClientesVarios();
        $this->dispatch('enfocar-busqueda');
    }

    // ───────────────────────── Utilidades ─────────────────────────

    /** Convierte lo que escriba el cajero en un número válido (o 0). */
    private function numero(mixed $valor): float
    {
        $v = str_replace(',', '.', trim((string) $valor));

        return is_numeric($v) && (float) $v > 0 ? round((float) $v, 4) : 0.0;
    }

    private function formato(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
