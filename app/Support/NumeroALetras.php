<?php

namespace App\Support;

/** Convierte montos a letras para la leyenda "SON: ..." de la representación impresa. */
class NumeroALetras
{
    private const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function convertir(string|float $monto, string $moneda = 'PEN'): string
    {
        [$entero, $decimales] = array_pad(explode('.', number_format((float) $monto, 2, '.', '')), 2, '00');
        $entero = (int) $entero;

        $letras = $entero === 0 ? 'CERO' : self::numero($entero);
        $nombreMoneda = $moneda === 'USD' ? 'DÓLARES AMERICANOS' : 'SOLES';

        return "SON: {$letras} CON {$decimales}/100 {$nombreMoneda}";
    }

    private static function numero(int $n): string
    {
        if ($n >= 1_000_000) {
            $millones = intdiv($n, 1_000_000);
            $resto = $n % 1_000_000;
            $txt = $millones === 1 ? 'UN MILLÓN' : self::numero($millones).' MILLONES';

            return trim($txt.' '.($resto ? self::numero($resto) : ''));
        }

        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;
            $txt = $miles === 1 ? 'MIL' : self::apocope(self::numero($miles)).' MIL';

            return trim($txt.' '.($resto ? self::numero($resto) : ''));
        }

        if ($n === 100) {
            return 'CIEN';
        }

        if ($n > 100) {
            $resto = $n % 100;

            return trim(self::CENTENAS[intdiv($n, 100)].' '.($resto ? self::numero($resto) : ''));
        }

        if ($n < 30) {
            return self::UNIDADES[$n];
        }

        $u = $n % 10;

        return self::DECENAS[intdiv($n, 10)].($u ? ' Y '.self::UNIDADES[$u] : '');
    }

    /** "VEINTIUNO MIL" → "VEINTIÚN MIL", "UNO MIL" → "UN MIL" */
    private static function apocope(string $txt): string
    {
        return preg_replace(['/VEINTIUNO$/', '/UNO$/'], ['VEINTIÚN', 'UN'], $txt);
    }
}
