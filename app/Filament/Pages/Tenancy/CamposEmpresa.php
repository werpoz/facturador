<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\RegimenTributario;
use App\Models\Empresa;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

class CamposEmpresa
{
    public static function datos(): Section
    {
        return Section::make('Datos fiscales')
            ->description('Aparecen en tus comprobantes tal como los escribas aquí.')
            ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->schema([
                TextInput::make('ruc')
                    ->label('RUC')
                    ->required()
                    ->length(11)
                    ->numeric()
                    ->rule('regex:/^(10|15|16|17|20)\d{9}$/')
                    ->validationMessages(['regex' => 'El RUC debe empezar con 10, 15, 16, 17 o 20.'])
                    ->unique(Empresa::class, 'ruc', ignoreRecord: true),
                TextInput::make('razon_social')->label('Razón social')->required()->maxLength(255)->columnSpan(['xl' => 3]),
                Select::make('regimen_tributario')
                    ->label('Régimen tributario')
                    ->options(RegimenTributario::class)
                    ->default(RegimenTributario::Mype)
                    ->required()
                    ->helperText('Si no lo sabes, consúltalo en tu ficha RUC. En el Nuevo RUS solo se emiten boletas.')
                    ->columnSpan(['md' => 2]),
                TextInput::make('nombre_comercial')->label('Nombre comercial')->maxLength(255)->columnSpan(['md' => 2]),
                TextInput::make('direccion')->label('Dirección fiscal')->maxLength(255)->columnSpanFull(),
                TextInput::make('ubigeo')->label('Ubigeo')->length(6)->numeric()->placeholder('150101'),
                TextInput::make('departamento'),
                TextInput::make('provincia'),
                TextInput::make('distrito'),
                TextInput::make('telefono')->label('Teléfono')->tel(),
                TextInput::make('email')->label('Correo')->email(),
                Toggle::make('bloquear_venta_sin_stock')
                    ->label('No permitir vender sin stock')
                    ->helperText('Apagado: se puede vender aunque el sistema marque cero (el stock queda en negativo y te avisa). Encendido: la venta se bloquea.')
                    ->columnSpanFull(),
                FileUpload::make('logo')->image()->disk('public')->directory('logos')->maxSize(1024)->columnSpanFull(),
            ]);
    }

    public static function pse(): Section
    {
        return Section::make('Conexión con el PSE')
            ->description('Usa "Simulado" para probar el sistema sin enviar nada a SUNAT.')
            ->columns(2)
            ->schema([
                Select::make('pse_proveedor')
                    ->label('Proveedor')
                    ->options(fn () => array_filter([
                        'fake' => config('facturacion.permitir_simulador') ? 'Simulado (pruebas, sin valor tributario)' : null,
                        'gior' => 'Gior Technology',
                    ]))
                    ->default(fn () => config('facturacion.permitir_simulador') ? 'fake' : 'gior')
                    ->required()
                    ->live(),
                Select::make('pse_entorno')
                    ->label('Entorno')
                    ->options(['beta' => 'Beta (pruebas)', 'produccion' => 'Producción'])
                    ->default('beta')
                    ->required()
                    ->rule(fn ($get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                        if ($value === 'produccion' && $get('pse_proveedor') === 'fake') {
                            $fail('El proveedor simulado no puede usarse en producción: elige un PSE real.');
                        }
                    }),
                TextInput::make('pse_token')
                    ->label('Token / API key')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state) => filled($state)) // vacío = conservar el actual
                    ->required(fn ($get, ?Empresa $record) => $get('pse_proveedor') === 'gior' && blank($record?->pse_token))
                    ->helperText('Se guarda cifrado. Déjalo vacío para mantener el actual.')
                    ->columnSpanFull(),
            ]);
    }
}
