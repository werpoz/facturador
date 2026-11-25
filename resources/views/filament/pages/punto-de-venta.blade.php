@php
    $total = $this->totales['total'];
    $hayItems = count($this->carrito) > 0;
    $venta = $this->ultimaVenta;
    $cliente = $this->cliente;
    $esFactura = $this->tipo === '01';
    $faltaRuc = $esFactura && $cliente?->tipo_documento !== \App\Enums\TipoDocumentoIdentidad::Ruc;
@endphp

<x-filament-panels::page>
    <div class="pos"
         x-data="{ panel: false, buscandoCliente: false }"
         x-on:venta-registrada.window="panel = false"
         x-on:enfocar-busqueda.window="$nextTick(() => $refs.buscar?.focus())">

        {{-- ═════════ Productos ═════════ --}}
        <section class="pos-productos">
            <label class="pos-buscar">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="pos-buscar-icono" />
                <input type="search" x-ref="buscar" autocomplete="off" enterkeyhint="search"
                       placeholder="Buscar producto o código…"
                       wire:model.live.debounce.250ms="busqueda"
                       wire:keydown.enter="agregarPrimero" />
            </label>

            <div class="pos-grid">
                @forelse ($this->productos as $producto)
                    @php
                        $enCarrito = $this->carrito['p'.$producto->id]['cantidad'] ?? null;
                        $agotado = $producto->estaAgotado();
                    @endphp
                    <button type="button" wire:key="prod-{{ $producto->id }}" wire:click="agregar({{ $producto->id }})"
                            @class(['pos-producto', 'pos-producto-activo' => $enCarrito, 'pos-producto-agotado' => $agotado])
                            @disabled($agotado && $this->bloqueaSinStock())>
                        @if ($enCarrito)<span class="pos-producto-cant">{{ $enCarrito }}</span>@endif
                        <span class="pos-producto-nombre">{{ $producto->descripcion }}</span>
                        <span class="pos-producto-pie">
                            <span class="pos-producto-precio">S/ {{ number_format((float) $producto->precio_unitario, 2) }}</span>
                            @if ($producto->controla_stock)
                                <span @class(['pos-producto-stock', 'agotado' => $agotado, 'bajo' => ! $agotado && $producto->stockBajo()])>
                                    {{ $agotado ? 'Agotado' : 'Quedan '.\App\Services\Inventario\StockService::fmt($producto->stock) }}
                                </span>
                            @elseif ($producto->codigo)
                                <span class="pos-producto-codigo">{{ $producto->codigo }}</span>
                            @endif
                        </span>
                    </button>
                @empty
                    <p class="pos-vacio">
                        @if (trim($this->busqueda) !== '')
                            No hay productos que coincidan con «{{ $this->busqueda }}».
                        @else
                            Aún no tienes productos. Créalos en Productos/Servicios.
                        @endif
                    </p>
                @endforelse
            </div>
        </section>

        {{-- ═════════ Venta (en celular: panel a pantalla completa) ═════════ --}}
        <aside class="pos-panel" x-bind:class="{ 'pos-panel-abierto': panel }">
            <header class="pos-panel-cabecera">
                <strong>Tu venta</strong>
                <button type="button" class="pos-icono-btn" x-on:click="panel = false" aria-label="Seguir agregando productos">
                    <x-filament::icon icon="heroicon-o-x-mark" />
                </button>
            </header>

            <div class="pos-panel-cuerpo">
                {{-- Boleta / Factura --}}
                @if ($this->permiteFactura())
                    <div class="pos-segmento" role="group" aria-label="Tipo de comprobante">
                        <button type="button" wire:click="$set('tipo', '03')" @class(['activo' => ! $esFactura])>Boleta</button>
                        <button type="button" wire:click="$set('tipo', '01')" @class(['activo' => $esFactura])>Factura</button>
                    </div>
                @endif

                {{-- Cliente --}}
                <div class="pos-cliente">
                    <div class="pos-cliente-actual">
                        <x-filament::icon icon="heroicon-o-user" class="pos-cliente-icono" />
                        <div class="pos-cliente-datos">
                            <strong>{{ $cliente?->razon_social ?? 'Sin cliente' }}</strong>
                            @if ($cliente && $cliente->tipo_documento !== \App\Enums\TipoDocumentoIdentidad::SinDocumento)
                                <span>{{ $cliente->tipo_documento->getLabel() }} {{ $cliente->numero_documento }}</span>
                            @endif
                        </div>
                        <button type="button" class="pos-enlace" x-on:click="buscandoCliente = ! buscandoCliente"
                                x-text="buscandoCliente ? 'Cerrar' : 'Cambiar'">Cambiar</button>
                    </div>

                    @if ($faltaRuc)
                        <p class="pos-aviso">La factura necesita un cliente con RUC. Búscalo o regístralo.</p>
                    @endif

                    <div x-show="buscandoCliente" x-cloak @class(['pos-cliente-buscar', 'pos-forzar' => $faltaRuc])>
                        <input type="text" inputmode="numeric" placeholder="DNI, RUC o nombre" autocomplete="off"
                               wire:model.live.debounce.300ms="buscarCliente" />

                        @foreach ($this->clientesEncontrados as $c)
                            <button type="button" class="pos-cliente-opcion" wire:key="cli-{{ $c->id }}"
                                    wire:click="seleccionarCliente({{ $c->id }})" x-on:click="buscandoCliente = false">
                                <strong>{{ $c->razon_social }}</strong>
                                <span>{{ $c->tipo_documento->getLabel() }} {{ $c->numero_documento }}</span>
                            </button>
                        @endforeach

                        @if ($this->documentoNuevo)
                            <div class="pos-cliente-nuevo">
                                <span>{{ $this->documentoNuevo->getLabel() }} nuevo. Escribe su nombre para registrarlo:</span>
                                <input type="text" placeholder="{{ $this->documentoNuevo === \App\Enums\TipoDocumentoIdentidad::Ruc ? 'Razón social' : 'Nombre completo' }}"
                                       wire:model="nuevoClienteNombre" wire:keydown.enter="registrarCliente" />
                                <button type="button" class="pos-btn-secundario" wire:click="registrarCliente" x-on:click="buscandoCliente = false">Registrar y usar</button>
                            </div>
                        @endif

                        @if ($cliente?->tipo_documento !== \App\Enums\TipoDocumentoIdentidad::SinDocumento)
                            <button type="button" class="pos-enlace" wire:click="usarClientesVarios" x-on:click="buscandoCliente = false">Usar «Clientes varios»</button>
                        @endif
                    </div>
                </div>

                {{-- Productos de la venta --}}
                <div class="pos-items">
                    @forelse ($this->carrito as $clave => $linea)
                        <div class="pos-item" wire:key="item-{{ $clave }}">
                            <div class="pos-item-info">
                                <strong>{{ $linea['descripcion'] }}</strong>
                                <span>S/ {{ number_format((float) $linea['precio'], 2) }} c/u</span>
                                @if ($this->excedeStock($linea))
                                    <span class="pos-item-aviso">
                                        {{ $linea['stock'] <= 0 ? 'Sin stock registrado' : 'Solo quedan '.\App\Services\Inventario\StockService::fmt($linea['stock']) }}
                                    </span>
                                @endif
                            </div>
                            <div class="pos-cantidad">
                                <button type="button" wire:click="decrementar('{{ $clave }}')" aria-label="Quitar uno">−</button>
                                <input type="text" inputmode="decimal" aria-label="Cantidad"
                                       wire:model.blur="carrito.{{ $clave }}.cantidad" />
                                <button type="button" wire:click="incrementar('{{ $clave }}')" aria-label="Agregar uno">+</button>
                            </div>
                            <strong class="pos-item-importe">S/ {{ number_format((float) $this->importe($linea), 2) }}</strong>
                        </div>
                    @empty
                        <p class="pos-vacio">Toca un producto para agregarlo a la venta.</p>
                    @endforelse

                    @if ($hayItems)
                        <button type="button" class="pos-enlace pos-vaciar" wire:click="vaciar" wire:confirm="¿Quitar todos los productos de la venta?">Vaciar venta</button>
                    @endif
                </div>
            </div>

            {{-- Cobro --}}
            <footer class="pos-cobro">
                <div class="pos-total">
                    <span>Total</span>
                    <strong>S/ {{ number_format((float) $total, 2) }}</strong>
                </div>

                <div class="pos-medios" role="group" aria-label="Medio de pago">
                    @foreach (\App\Enums\MedioPago::cases() as $m)
                        <button type="button" wire:click="$set('medio', '{{ $m->value }}')" @class(['activo' => $this->medio === $m->value])>
                            <x-filament::icon :icon="$m->getIcon()" />
                            {{ $m->getLabel() }}
                        </button>
                    @endforeach
                </div>

                @if ($this->medio === 'efectivo' && $hayItems)
                    <div class="pos-efectivo">
                        <div class="pos-billetes">
                            @foreach ($this->billetes as $i => $b)
                                <button type="button" wire:click="$set('recibido', '{{ $b }}')" @class(['activo' => $this->recibido === $b])>
                                    {{ $i === 0 ? 'Exacto' : 'S/ '.rtrim(rtrim($b, '0'), '.') }}
                                </button>
                            @endforeach
                        </div>
                        <label class="pos-recibido">
                            <span>Recibido</span>
                            <input type="text" inputmode="decimal" placeholder="{{ number_format((float) $total, 2) }}" wire:model.live.debounce.400ms="recibido" />
                        </label>
                        @if ($this->vuelto !== null)
                            <div @class(['pos-vuelto', 'pos-vuelto-falta' => $this->vuelto < 0])>
                                <span>{{ $this->vuelto < 0 ? 'Falta' : 'Vuelto' }}</span>
                                <strong>S/ {{ number_format(abs($this->vuelto), 2) }}</strong>
                            </div>
                        @endif
                    </div>
                @endif

                <button type="button" class="pos-btn-cobrar" wire:click="cobrar" wire:loading.attr="disabled" wire:target="cobrar"
                        @disabled(! $hayItems || $faltaRuc || ($this->vuelto !== null && $this->vuelto < 0))>
                    <span wire:loading.remove wire:target="cobrar">Cobrar S/ {{ number_format((float) $total, 2) }}</span>
                    <span wire:loading wire:target="cobrar">Registrando…</span>
                </button>
            </footer>
        </aside>

        {{-- ═════════ Barra inferior (solo celular) ═════════ --}}
        <div class="pos-barra">
            <div class="pos-barra-resumen">
                <span>{{ rtrim(rtrim(number_format($this->unidades, 2), '0'), '.') ?: '0' }} {{ $this->unidades == 1 ? 'producto' : 'productos' }}</span>
                <strong>S/ {{ number_format((float) $total, 2) }}</strong>
            </div>
            <button type="button" class="pos-btn-cobrar" x-on:click="panel = true" @disabled(! $hayItems)>Ver y cobrar</button>
        </div>

        {{-- ═════════ Venta registrada ═════════ --}}
        @if ($venta)
            @php $pago = $venta->venta?->pagos->last(); @endphp
            <div class="pos-exito" role="dialog" aria-modal="true" aria-labelledby="pos-exito-titulo">
                <div class="pos-exito-caja">
                    <div class="pos-exito-check"><x-filament::icon icon="heroicon-o-check" /></div>
                    <h2 id="pos-exito-titulo">Venta registrada</h2>
                    <p class="pos-exito-numero">{{ $venta->tipo_comprobante->getLabel() }} {{ $venta->numero }}</p>
                    <p class="pos-exito-total">S/ {{ number_format((float) $venta->total, 2) }}</p>
                    @if ($pago)
                        <p class="pos-exito-pago">
                            Pagó con {{ mb_strtolower($pago->medio->getLabel()) }}@if ($pago->vuelto > 0): recibido S/ {{ number_format((float) $pago->recibido, 2) }}@endif
                        </p>
                        @if ($pago->vuelto > 0)
                            <p class="pos-exito-vuelto">Vuelto: <strong>S/ {{ number_format((float) $pago->vuelto, 2) }}</strong></p>
                        @endif
                    @endif
                    <div class="pos-exito-acciones">
                        <a class="pos-btn-secundario" target="_blank" rel="noopener"
                           href="{{ route('comprobantes.imprimir', ['comprobante' => $venta, 'formato' => 'ticket']) }}">
                            <x-filament::icon icon="heroicon-o-printer" /> Imprimir ticket
                        </a>
                        <button type="button" class="pos-btn-cobrar" wire:click="nuevaVenta">Nueva venta</button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
