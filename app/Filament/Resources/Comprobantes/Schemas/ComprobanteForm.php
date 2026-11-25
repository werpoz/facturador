<?php

namespace App\Filament\Resources\Comprobantes\Schemas;

use App\Enums\EstadoComprobante;
use App\Enums\Moneda;
use App\Enums\MotivoNotaCredito;
use App\Enums\Permiso;
use App\Enums\TipoAfectacionIgv;
use App\Enums\TipoComprobante;
use App\Enums\UnidadMedida;
use App\Filament\Resources\Clientes\ClienteResource;
use App\Models\Comprobante;
use App\Models\Producto;
use App\Services\Facturacion\CalculadoraTotales;
use App\Support\Permisos;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ComprobanteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Comprobante')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        ToggleButtons::make('tipo_comprobante')
                            ->label('Tipo')
                            ->options(fn () => collect(TipoComprobante::cases())
                                ->reject(fn (TipoComprobante $t) => $t === TipoComprobante::Factura && ! Filament::getTenant()?->permiteFacturas())
                                ->reject(fn (TipoComprobante $t) => $t->esNota() && ! Permisos::puede(Permiso::EmitirNotas))
                                ->mapWithKeys(fn (TipoComprobante $t) => [$t->value => $t->getLabel()]))
                            ->default(TipoComprobante::Boleta)
                            ->inline()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                $set('serie_id', self::serieDefecto(self::tipo($get)));
                                $set('comprobante_referencia_id', null);
                                $set('motivo_codigo', null);
                            })
                            ->columnSpanFull(),
                        Select::make('serie_id')
                            ->label('Serie')
                            ->options(fn (Get $get) => self::series(self::tipo($get)))
                            ->default(fn () => self::serieDefecto(TipoComprobante::Boleta))
                            ->required()
                            ->native(false)
                            ->helperText(fn (Get $get) => self::series(self::tipo($get)) === []
                                ? 'No hay series para este tipo. Créalas en Configuración → Series.' : null),
                        DatePicker::make('fecha_emision')
                            ->label('Fecha de emisión')
                            ->default(now())
                            ->maxDate(now())
                            ->minDate(today()->subDays((int) config('facturacion.dias_maximos_envio', 3)))
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required(),
                        Select::make('moneda')
                            ->options(Moneda::class)
                            ->default(Moneda::Soles)
                            ->required()
                            ->live(),
                        Select::make('cliente_id')
                            ->label('Cliente')
                            ->relationship(
                                'cliente',
                                'razon_social',
                                fn (Builder $query) => $query->where('empresa_id', Filament::getTenant()?->id),
                            )
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->etiqueta)
                            ->searchable(['numero_documento', 'razon_social'])
                            ->preload()
                            ->required()
                            ->createOptionForm(ClienteResource::campos())
                            ->createOptionUsing(fn (array $data) => Filament::getTenant()->clientes()->create($data)->getKey())
                            ->helperText(fn (Get $get) => self::tipo($get) === TipoComprobante::Factura ? 'Las facturas requieren un cliente con RUC.' : null)
                            ->columnSpan(2),
                        ToggleButtons::make('forma_pago')
                            ->label('Forma de pago')
                            ->options(['Contado' => 'Contado', 'Credito' => 'Crédito'])
                            ->default('Contado')
                            ->inline()
                            ->live()
                            ->required(),
                        DatePicker::make('fecha_vencimiento')
                            ->label('Fecha de vencimiento')
                            ->visible(fn (Get $get) => $get('forma_pago') === 'Credito')
                            ->required(fn (Get $get) => $get('forma_pago') === 'Credito')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->after('fecha_emision'),
                    ]),

                Section::make('Totales')
                    ->columnSpan(1)
                    // En celular los totales van al final, después de los productos
                    ->extraAttributes(['class' => 'qf-totales qf-al-final'])
                    ->schema([
                        TextEntry::make('resumen_gravadas')->inlineLabel()->label('Gravadas')
                            ->state(fn (Get $get) => self::monto($get, 'op_gravadas')),
                        TextEntry::make('resumen_exoneradas')->inlineLabel()->label('Exoneradas')
                            ->state(fn (Get $get) => self::monto($get, 'op_exoneradas'))
                            ->visible(fn (Get $get) => self::totales($get)['op_exoneradas'] > 0),
                        TextEntry::make('resumen_inafectas')->inlineLabel()->label('Inafectas')
                            ->state(fn (Get $get) => self::monto($get, 'op_inafectas'))
                            ->visible(fn (Get $get) => self::totales($get)['op_inafectas'] > 0),
                        TextEntry::make('resumen_gratuitas')->inlineLabel()->label('Gratuitas')
                            ->state(fn (Get $get) => self::monto($get, 'op_gratuitas'))
                            ->visible(fn (Get $get) => self::totales($get)['op_gratuitas'] > 0),
                        TextEntry::make('resumen_igv')->inlineLabel()->label('IGV ('.((float) config('facturacion.igv') * 100).'%)')
                            ->state(fn (Get $get) => self::monto($get, 'igv')),
                        TextEntry::make('resumen_total')->inlineLabel()->label('TOTAL')
                            ->state(fn (Get $get) => self::monto($get, 'total'))
                            ->size('lg')
                            ->weight('bold')
                            ->color('primary'),
                    ]),

                Section::make('Documento que modifica')
                    ->description('Comprobante al que se aplica la nota de crédito o débito.')
                    ->visible(fn (Get $get) => self::tipo($get)?->esNota())
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Select::make('comprobante_referencia_id')
                            ->label('Comprobante de referencia')
                            ->options(fn () => Comprobante::query()
                                ->where('empresa_id', Filament::getTenant()?->id)
                                ->whereIn('tipo_comprobante', [TipoComprobante::Factura, TipoComprobante::Boleta])
                                ->whereIn('estado', [EstadoComprobante::Aceptado, EstadoComprobante::Observado])
                                ->latest('fecha_emision')
                                ->limit(200)
                                ->get()
                                ->mapWithKeys(fn (Comprobante $c) => [$c->id => "{$c->numero} · {$c->cliente->razon_social} · {$c->total}"]))
                            ->searchable()
                            ->required(fn (Get $get) => self::tipo($get)?->esNota())
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get, $state) => self::copiarReferencia($set, $get, $state)),
                        Select::make('motivo_codigo')
                            ->label('Motivo')
                            ->options(fn (Get $get) => self::tipo($get) === TipoComprobante::NotaDebito
                                ? ['01' => '01 - Intereses por mora', '02' => '02 - Aumento en el valor', '03' => '03 - Penalidades / otros conceptos']
                                : MotivoNotaCredito::class)
                            ->required(fn (Get $get) => self::tipo($get)?->esNota()),
                        TextInput::make('motivo_descripcion')
                            ->label('Sustento')
                            ->required(fn (Get $get) => self::tipo($get)?->esNota())
                            ->maxLength(250),
                    ]),

                Section::make('Detalle')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship('items')
                            ->orderColumn('orden')
                            ->table([
                                TableColumn::make('Producto')->width('22%'),
                                TableColumn::make('Descripción')->markAsRequired(),
                                TableColumn::make('Unidad')->width('100px'),
                                TableColumn::make('Cant.')->width('90px')->markAsRequired(),
                                TableColumn::make('P. unit. (inc. IGV)')->width('130px')->markAsRequired(),
                                TableColumn::make('IGV')->width('130px'),
                                TableColumn::make('Total')->width('110px'),
                            ])
                            ->schema([
                                Select::make('producto_id')
                                    ->hiddenLabel()
                                    ->options(fn () => Producto::query()
                                        ->where('empresa_id', Filament::getTenant()?->id)
                                        ->where('activo', true)
                                        ->orderBy('descripcion')
                                        ->pluck('descripcion', 'id'))
                                    ->searchable()
                                    ->placeholder('Buscar...')
                                    ->extraFieldWrapperAttributes(['data-etiqueta' => 'Producto'])
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, $state) {
                                        if ($producto = Producto::find($state)) {
                                            $set('codigo', $producto->codigo);
                                            $set('descripcion', $producto->descripcion);
                                            $set('unidad_medida', $producto->unidad_medida);
                                            $set('precio_unitario', $producto->precio_unitario);
                                            $set('tipo_afectacion_igv', $producto->tipo_afectacion_igv);
                                        }
                                    }),
                                TextInput::make('descripcion')->hiddenLabel()->required()->maxLength(255)->placeholder('Descripción')
                                    ->readOnly(fn () => ! self::precioLibre())
                                    ->extraFieldWrapperAttributes(['data-etiqueta' => 'Descripción']),
                                Select::make('unidad_medida')->hiddenLabel()
                                    ->options(collect(UnidadMedida::cases())->mapWithKeys(fn ($u) => [$u->value => $u->value]))
                                    ->default(UnidadMedida::Unidad->value)
                                    ->extraFieldWrapperAttributes(['data-etiqueta' => 'Unidad'])
                                    ->required()
                                    ->selectablePlaceholder(false),
                                TextInput::make('cantidad')->hiddenLabel()
                                    ->numeric()->minValue(0.0001)->default(1)->required()
                                    ->placeholder('Cant.')
                                    ->extraFieldWrapperAttributes(['data-etiqueta' => 'Cantidad'])
                                    ->live(onBlur: true),
                                TextInput::make('precio_unitario')->hiddenLabel()
                                    ->numeric()->minValue(0)->step('0.01')->required()
                                    ->placeholder('Precio')
                                    ->readOnly(fn () => ! self::precioLibre())
                                    ->extraFieldWrapperAttributes(['data-etiqueta' => 'Precio unitario (con IGV)'])
                                    ->live(onBlur: true),
                                Select::make('tipo_afectacion_igv')->hiddenLabel()
                                    ->options([
                                        TipoAfectacionIgv::Gravado->value => 'Gravado',
                                        TipoAfectacionIgv::Exonerado->value => 'Exonerado',
                                        TipoAfectacionIgv::Inafecto->value => 'Inafecto',
                                        TipoAfectacionIgv::GravadoGratuito->value => 'Gratuito',
                                    ])
                                    ->default(TipoAfectacionIgv::Gravado->value)
                                    ->disabled(fn () => ! self::precioLibre())
                                    ->dehydrated()
                                    ->extraFieldWrapperAttributes(['data-etiqueta' => 'IGV'])
                                    ->required()
                                    ->selectablePlaceholder(false)
                                    ->live(),
                                TextEntry::make('total_linea')->hiddenLabel()
                                    ->state(fn (Get $get) => self::totalLinea($get))
                                    ->alignEnd(),
                                TextInput::make('codigo')->hidden()->dehydrated(),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::calcularItem($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::calcularItem($data))
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Agregar ítem')
                            ->live(),
                    ]),

                Grid::make(1)->columnSpanFull()->schema([
                    Textarea::make('observaciones')->rows(2)->maxLength(500),
                ]),
            ]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Las notas copian ítems de un comprobante ya emitido; solo las emite quien tiene ese permiso */
    private static function esNotaActual(): bool
    {
        return Permisos::puede(Permiso::EmitirNotas);
    }

    public static function tipo(Get $get): ?TipoComprobante
    {
        $v = $get('tipo_comprobante');

        return $v instanceof TipoComprobante ? $v : TipoComprobante::tryFrom((string) $v);
    }

    /** @return array<int,string> */
    public static function series(?TipoComprobante $tipo): array
    {
        $tenant = Filament::getTenant();
        if (! $tipo || ! $tenant) {
            return [];
        }

        return $tenant->series()
            ->where('tipo_comprobante', $tipo)
            ->where('activo', true)
            ->orderBy('serie')
            ->pluck('serie', 'id')
            ->all();
    }

    public static function serieDefecto(?TipoComprobante $tipo): ?int
    {
        return array_key_first(self::series($tipo));
    }

    /** ¿El usuario puede escribir precios y descripciones libres? (solo quien gestiona productos) */
    public static function precioLibre(): bool
    {
        return Permisos::puede(Permiso::GestionarProductos);
    }

    public static function calcularItem(array $data): array
    {
        // Sin ese permiso, lo que valga viene SIEMPRE del catálogo, sin importar lo que mande el navegador
        if (! self::precioLibre() && ! self::esNotaActual()) {
            $producto = Producto::query()->where('empresa_id', Filament::getTenant()?->id)->where('activo', true)
                ->find($data['producto_id'] ?? null);

            if (! $producto) {
                throw ValidationException::withMessages([
                    'data.items' => 'Elige cada producto del catálogo: tu usuario no puede vender ítems escritos a mano.',
                ]);
            }

            $data = array_merge($data, [
                'codigo' => $producto->codigo,
                'descripcion' => $producto->descripcion,
                'unidad_medida' => $producto->unidad_medida->value,
                'precio_unitario' => $producto->precio_unitario,
                'tipo_afectacion_igv' => $producto->tipo_afectacion_igv->value,
            ]);
        }

        $calculo = app(CalculadoraTotales::class)->calcularItem(
            $data['cantidad'] ?? 0,
            $data['precio_unitario'] ?? 0,
            $data['tipo_afectacion_igv'] ?? TipoAfectacionIgv::Gravado,
        );

        return array_merge($data, $calculo);
    }

    /** Totales en vivo del formulario (lo definitivo se recalcula en el servidor al guardar). */
    public static function totales(Get $get): array
    {
        $calc = app(CalculadoraTotales::class);
        $items = [];

        foreach ($get('items') ?? [] as $item) {
            if (blank($item['tipo_afectacion_igv'] ?? null)) {
                continue;
            }
            $afectacion = $item['tipo_afectacion_igv'];
            $items[] = ['tipo_afectacion_igv' => $afectacion]
                + $calc->calcularItem($item['cantidad'] ?? 0, $item['precio_unitario'] ?? 0, $afectacion);
        }

        return $calc->calcularTotales($items);
    }

    public static function monto(Get $get, string $clave): string
    {
        return self::simbolo($get).' '.number_format((float) self::totales($get)[$clave], 2);
    }

    private static function simbolo(Get $get, string $prefijo = ''): string
    {
        $m = $get($prefijo.'moneda');
        $m = $m instanceof Moneda ? $m : Moneda::tryFrom((string) $m);

        return $m?->simbolo() ?? 'S/';
    }

    private static function totalLinea(Get $get): string
    {
        if (blank($get('tipo_afectacion_igv'))) {
            return '-';
        }
        $c = app(CalculadoraTotales::class)
            ->calcularItem($get('cantidad') ?? 0, $get('precio_unitario') ?? 0, $get('tipo_afectacion_igv'));

        return self::simbolo($get, '../../').' '.number_format((float) $c['total'], 2);
    }

    /** Al elegir el comprobante de referencia de una nota, copia cliente, moneda, serie e ítems. */
    private static function copiarReferencia(Set $set, Get $get, $id): void
    {
        $ref = Comprobante::with('items')->find($id);
        if (! $ref || $ref->empresa_id !== Filament::getTenant()?->id) {
            return;
        }

        foreach (self::datosDesdeReferencia($ref, self::tipo($get) ?? TipoComprobante::NotaCredito) as $campo => $valor) {
            if ($campo !== 'tipo_comprobante' && $campo !== 'comprobante_referencia_id') {
                $set($campo, $valor);
            }
        }
    }

    /** Datos iniciales de una nota de crédito/débito a partir del comprobante que modifica. */
    public static function datosDesdeReferencia(Comprobante $ref, TipoComprobante $tipo): array
    {
        // Serie de la nota que empiece con la misma letra (F para facturas, B para boletas)
        $series = self::series($tipo);
        $serieId = collect($series)->filter(fn ($s) => str_starts_with($s, $ref->serie[0]))->keys()->first();

        $items = [];
        foreach ($ref->items as $i) {
            $items['item-'.$i->id] = [
                'producto_id' => $i->producto_id,
                'codigo' => $i->codigo,
                'descripcion' => $i->descripcion,
                'unidad_medida' => $i->unidad_medida->value,
                'cantidad' => (string) $i->cantidad,
                'precio_unitario' => (string) $i->precio_unitario,
                'tipo_afectacion_igv' => $i->tipo_afectacion_igv->value,
            ];
        }

        return [
            'tipo_comprobante' => $tipo,
            'comprobante_referencia_id' => $ref->id,
            'serie_id' => $serieId,
            'cliente_id' => $ref->cliente_id,
            'moneda' => $ref->moneda,
            'forma_pago' => 'Contado',
            'items' => $items,
        ];
    }
}
