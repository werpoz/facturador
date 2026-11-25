<?php

namespace App\Observers;

use App\Enums\EstadoComprobante;
use App\Models\Comprobante;
use App\Services\Inventario\StockService;
use App\Services\Ventas\VentaService;

class ComprobanteObserver
{
    public function __construct(private VentaService $ventas, private StockService $stock) {}

    public function saved(Comprobante $comprobante): void
    {
        $this->ventas->sincronizar($comprobante);
    }

    public function updated(Comprobante $comprobante): void
    {
        // Recibió su número = se emitió: la mercadería sale (o regresa, si es una devolución).
        // Ocurre dentro de la transacción de emisión: si no hay stock y la empresa lo exige, no se emite.
        if ($comprobante->wasChanged('correlativo') && $comprobante->correlativo !== null) {
            $this->stock->aplicarComprobante($comprobante);
        }

        // SUNAT lo rechazó (o se anuló): se deshace su movimiento
        if ($comprobante->wasChanged('estado')
            && in_array($comprobante->estado, [EstadoComprobante::Rechazado, EstadoComprobante::Anulado], true)) {
            $this->stock->revertirComprobante($comprobante);
        }
    }

    public function deleted(Comprobante $comprobante): void
    {
        $this->ventas->alEliminar($comprobante);
    }
}
