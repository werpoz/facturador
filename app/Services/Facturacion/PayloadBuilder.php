<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;

/**
 * Convierte un Comprobante en un arreglo neutro con todos los datos que pide SUNAT.
 * Cada proveedor (Gior u otro) transforma este arreglo al formato de su API.
 */
class PayloadBuilder
{
    public function construir(Comprobante $c): array
    {
        $c->loadMissing(['empresa', 'cliente', 'items', 'referencia']);

        return [
            'tipo_comprobante' => $c->tipo_comprobante->value,
            'serie' => $c->serie,
            'numero' => $c->correlativo,
            'fecha_emision' => $c->fecha_emision->format('Y-m-d'),
            'hora_emision' => ($c->emitido_at ?? $c->created_at)?->format('H:i:s'),
            'fecha_vencimiento' => $c->fecha_vencimiento?->format('Y-m-d'),
            'moneda' => $c->moneda->value,
            'forma_pago' => $c->forma_pago,
            'emisor' => [
                'ruc' => $c->empresa->ruc,
                'razon_social' => $c->empresa->razon_social,
                'nombre_comercial' => $c->empresa->nombre_comercial,
                'direccion' => $c->empresa->direccion,
                'ubigeo' => $c->empresa->ubigeo,
            ],
            'cliente' => [
                'tipo_documento' => $c->datosCliente()['tipo']->value,
                'numero_documento' => $c->datosCliente()['numero'],
                'razon_social' => $c->datosCliente()['nombre'],
                'direccion' => $c->datosCliente()['direccion'],
                'email' => $c->cliente->email,
            ],
            'documento_referencia' => $c->referencia ? [
                'tipo_comprobante' => $c->referencia->tipo_comprobante->value,
                'serie' => $c->referencia->serie,
                'numero' => $c->referencia->correlativo,
                'motivo_codigo' => $c->motivo_codigo,
                'motivo_descripcion' => $c->motivo_descripcion,
            ] : null,
            'totales' => [
                'op_gravadas' => $c->op_gravadas,
                'op_exoneradas' => $c->op_exoneradas,
                'op_inafectas' => $c->op_inafectas,
                'op_gratuitas' => $c->op_gratuitas,
                'igv_gratuitas' => $c->igv_gratuitas,
                'igv' => $c->igv,
                'total' => $c->total,
            ],
            'items' => $c->items->map(fn ($i) => [
                'codigo' => $i->codigo,
                'descripcion' => $i->descripcion,
                'unidad_medida' => $i->unidad_medida->value,
                'cantidad' => $i->cantidad,
                'valor_unitario' => $i->valor_unitario,
                // En un ítem gratuito el precio es 0; lo que se informa es su valor referencial (sin IGV)
                'precio_unitario' => $i->tipo_afectacion_igv->esGratuito() ? '0.0000' : $i->precio_unitario,
                'valor_referencial' => $i->valor_referencial,
                'es_gratuito' => $i->tipo_afectacion_igv->esGratuito(),
                'tipo_afectacion_igv' => $i->tipo_afectacion_igv->value,
                'valor_venta' => $i->valor_venta,
                'igv' => $i->igv,
                'total' => $i->total,
            ])->values()->all(),
            'leyendas' => $c->leyendas(),
            'observaciones' => $c->observaciones,
        ];
    }
}
