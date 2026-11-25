<?php

namespace Tests\Unit;

use App\Support\NumeroALetras;
use PHPUnit\Framework\TestCase;

class NumeroALetrasTest extends TestCase
{
    public function test_convierte_montos(): void
    {
        $this->assertSame('SON: CERO CON 50/100 SOLES', NumeroALetras::convertir('0.50'));
        $this->assertSame('SON: CIEN CON 00/100 SOLES', NumeroALetras::convertir(100));
        $this->assertSame('SON: CIENTO SETENTA Y NUEVE CON 70/100 SOLES', NumeroALetras::convertir('179.70'));
        $this->assertSame('SON: MIL DOSCIENTOS CON 00/100 SOLES', NumeroALetras::convertir(1200));
        $this->assertSame('SON: VEINTIÚN MIL QUINIENTOS UNO CON 00/100 SOLES', NumeroALetras::convertir(21501));
        $this->assertSame('SON: UN MILLÓN CON 00/100 DÓLARES AMERICANOS', NumeroALetras::convertir(1000000, 'USD'));
        $this->assertSame('SON: DOS MIL CUATROCIENTOS NOVENTA Y NUEVE CON 00/100 SOLES', NumeroALetras::convertir('2499.00'));
    }
}
