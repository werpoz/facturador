<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\Permiso;
use App\Services\Facturacion\EmisionService;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Illuminate\Database\Eloquent\Model;
use Filament\Schemas\Schema;

class EditarEmpresa extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Datos de la empresa';
    }

    public static function canView(Model $tenant): bool
    {
        return Permisos::puede(Permiso::GestionarEmpresa, $tenant);
    }

    /** Borrar lo emitido con el simulador antes de empezar a facturar de verdad. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('borrarPruebas')
                ->label('Borrar datos de prueba')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => app(EmisionService::class)->tieneDatosDePrueba($this->tenant))
                ->requiresConfirmation()
                ->modalHeading('¿Borrar los comprobantes de prueba?')
                ->modalDescription('Se eliminarán todos los comprobantes emitidos con el simulador y la numeración volverá a empezar. Tus clientes, productos y comprobantes reales no se tocan. Esta acción no se puede deshacer.')
                ->action(function () {
                    $n = app(EmisionService::class)->borrarDatosDePrueba($this->tenant);
                    Notification::make()->success()->title("Se borraron {$n} comprobantes de prueba")->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            CamposEmpresa::datos(),
            CamposEmpresa::pse(),
        ]);
    }
}
