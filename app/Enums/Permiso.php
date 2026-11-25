<?php

namespace App\Enums;

enum Permiso: string
{
    case VerVentas = 'ver_ventas';                  // listar y ver ventas y comprobantes
    case EmitirVentas = 'emitir_ventas';            // crear y emitir facturas y boletas
    case EmitirNotas = 'emitir_notas';              // notas de crédito/débito: anular, devolver, descontar
    case GestionarClientes = 'gestionar_clientes';
    case VerProductos = 'ver_productos';
    case GestionarProductos = 'gestionar_productos'; // crear productos y cambiar precios
    case GestionarSeries = 'gestionar_series';
    case GestionarEmpresa = 'gestionar_empresa';     // datos fiscales y conexión con el PSE
    case GestionarUsuarios = 'gestionar_usuarios';
    case VerBitacora = 'ver_bitacora';
}
