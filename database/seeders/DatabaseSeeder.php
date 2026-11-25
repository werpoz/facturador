<?php

namespace Database\Seeders;

use App\Enums\TipoAfectacionIgv;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Enums\UnidadMedida;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Datos de demostración: php artisan db:seed */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@demo.pe'],
            ['name' => 'Administrador', 'password' => 'password'],
        );

        $empresa = Empresa::firstOrCreate(['ruc' => '20000000001'], [
            'razon_social' => 'EMPRESA DEMO S.A.C.',
            'regimen_tributario' => 'mype',
            'nombre_comercial' => 'Demo Store',
            'direccion' => 'Av. Arequipa 123',
            'ubigeo' => '150101',
            'departamento' => 'LIMA',
            'provincia' => 'LIMA',
            'distrito' => 'LIMA',
            'pse_proveedor' => 'fake',
            'pse_entorno' => 'beta',
        ]);
        $user->forceFill(['es_superadmin' => true])->save();
        $empresa->users()->syncWithoutDetaching([$user->id => ['rol' => 'admin']]);

        // Usuarios de ejemplo para probar los roles
        foreach ([['vendedor@demo.pe', 'Vendedor Demo', 'vendedor'], ['contador@demo.pe', 'Contador Demo', 'contador']] as [$email, $nombre, $rol]) {
            $u = User::firstOrCreate(['email' => $email], ['name' => $nombre, 'password' => 'password']);
            $empresa->users()->syncWithoutDetaching([$u->id => ['rol' => $rol]]);
        }

        foreach ([
            [TipoComprobante::Factura, 'F001'], [TipoComprobante::Boleta, 'B001'],
            [TipoComprobante::NotaCredito, 'FC01'], [TipoComprobante::NotaCredito, 'BC01'],
            [TipoComprobante::NotaDebito, 'FD01'], [TipoComprobante::NotaDebito, 'BD01'],
        ] as [$tipo, $serie]) {
            $empresa->series()->firstOrCreate(['serie' => $serie], ['tipo_comprobante' => $tipo]);
        }

        foreach ([
            [TipoDocumentoIdentidad::SinDocumento, '00000000', 'CLIENTES VARIOS'],
            [TipoDocumentoIdentidad::Ruc, '20100070970', 'CLIENTE CORPORATIVO S.A.'],
            [TipoDocumentoIdentidad::Dni, '45678912', 'JUAN PÉREZ QUISPE'],
        ] as [$tipo, $doc, $nombre]) {
            $empresa->clientes()->firstOrCreate(
                ['tipo_documento' => $tipo, 'numero_documento' => $doc],
                ['razon_social' => $nombre],
            );
        }

        foreach ([
            // código, descripción, unidad, precio, IGV, stock (null = no lleva stock), mínimo
            ['P001', 'Laptop 15" Core i5', UnidadMedida::Unidad, '2499.00', TipoAfectacionIgv::Gravado, 5, 2],
            ['P002', 'Mouse inalámbrico', UnidadMedida::Unidad, '59.90', TipoAfectacionIgv::Gravado, 40, 10],
            ['S001', 'Servicio de soporte técnico (hora)', UnidadMedida::Servicio, '80.00', TipoAfectacionIgv::Gravado, null, null],
            ['L001', 'Libro "Contabilidad básica"', UnidadMedida::Unidad, '45.00', TipoAfectacionIgv::Exonerado, 3, 5],
        ] as [$codigo, $desc, $um, $precio, $igv, $stock, $minimo]) {
            $empresa->productos()->firstOrCreate(['codigo' => $codigo], [
                'descripcion' => $desc, 'unidad_medida' => $um,
                'precio_unitario' => $precio, 'tipo_afectacion_igv' => $igv,
                'controla_stock' => $stock !== null, 'stock' => $stock ?? 0, 'stock_minimo' => $minimo,
            ]);
        }
    }
}
