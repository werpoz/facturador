<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Catálogo 09 SUNAT */
enum MotivoNotaCredito: string implements HasLabel
{
    case AnulacionOperacion = '01';
    case AnulacionErrorRuc = '02';
    case CorreccionDescripcion = '03';
    case DescuentoGlobal = '04';
    case DescuentoItem = '05';
    case DevolucionTotal = '06';
    case DevolucionItem = '07';
    case Bonificacion = '08';
    case DisminucionValor = '09';
    case OtrosConceptos = '10';
    case AjusteMontoFechaPago = '13';

    /** Texto inicial del sustento, para que el usuario solo lo ajuste si quiere */
    public function sustentoSugerido(): string
    {
        return match ($this) {
            self::AnulacionOperacion => 'Anulación de la operación',
            self::DescuentoGlobal => 'Descuento otorgado al cliente',
            self::DevolucionTotal => 'Devolución total de los productos',
            self::DevolucionItem => 'Devolución de productos',
            default => '',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::AnulacionOperacion => '01 - Anulación de la operación',
            self::AnulacionErrorRuc => '02 - Anulación por error en el RUC',
            self::CorreccionDescripcion => '03 - Corrección por error en la descripción',
            self::DescuentoGlobal => '04 - Descuento global',
            self::DescuentoItem => '05 - Descuento por ítem',
            self::DevolucionTotal => '06 - Devolución total',
            self::DevolucionItem => '07 - Devolución por ítem',
            self::Bonificacion => '08 - Bonificación',
            self::DisminucionValor => '09 - Disminución en el valor',
            self::OtrosConceptos => '10 - Otros conceptos',
            self::AjusteMontoFechaPago => '13 - Ajustes de montos y/o fechas de pago',
        };
    }
}
