<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Models\Empresa;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RegistrarEmpresa extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Registrar empresa';
    }

    /** Solo el dueño del software (superadmin) da de alta empresas, salvo que el registro esté abierto. */
    public static function canView(): bool
    {
        return config('facturacion.registro_abierto') || (bool) auth()->user()?->es_superadmin;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            CamposEmpresa::datos(),
            CamposEmpresa::pse(),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $empresa = Empresa::create($data);
            $empresa->users()->attach(auth()->user(), ['rol' => 'admin']);

            // Series por defecto para empezar a facturar de inmediato
            foreach ([
                [TipoComprobante::Factura, 'F001'],
                [TipoComprobante::Boleta, 'B001'],
                [TipoComprobante::NotaCredito, 'FC01'],
                [TipoComprobante::NotaCredito, 'BC01'],
                [TipoComprobante::NotaDebito, 'FD01'],
                [TipoComprobante::NotaDebito, 'BD01'],
            ] as [$tipo, $serie]) {
                if (str_starts_with($serie, 'F') && ! $empresa->permiteFacturas()) {
                    continue; // Nuevo RUS: solo boletas
                }
                $empresa->series()->create(['tipo_comprobante' => $tipo, 'serie' => $serie]);
            }

            // Cliente genérico para boletas menores a S/ 700
            $empresa->clientes()->create([
                'tipo_documento' => TipoDocumentoIdentidad::SinDocumento,
                'numero_documento' => '00000000',
                'razon_social' => 'CLIENTES VARIOS',
            ]);

            return $empresa;
        });
    }
}
