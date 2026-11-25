<?php

namespace App\Filament\Resources\Usuarios;

use App\Enums\Permiso;
use App\Enums\RolEmpresa;
use App\Filament\Concerns\EtiquetasEnEspanol;
use App\Filament\Resources\Usuarios\Pages\ManageUsuarios;
use App\Models\Membresia;
use App\Support\Permisos;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Usuarios de la empresa y su rol. Solo para administradores. */
class UsuarioResource extends Resource
{
    use EtiquetasEnEspanol;

    protected static ?string $model = Membresia::class;

    protected static ?string $slug = 'usuarios';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Usuarios y roles';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $tenantRelationshipName = 'membresias';

    public static function canViewAny(): bool
    {
        return Permisos::puede(Permiso::GestionarUsuarios);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('email')
                ->label('Correo del usuario')
                ->email()
                ->required()
                ->visibleOn('create')
                ->helperText('Le llegará un correo para crear su contraseña. Si ya tiene cuenta, solo se le dará acceso a esta empresa.'),
            TextInput::make('nombre')
                ->label('Nombre')
                ->maxLength(255)
                ->visibleOn('create')
                ->helperText('Solo se usa si el usuario es nuevo.'),
            Radio::make('rol')
                ->options(RolEmpresa::class)
                ->descriptions(collect(RolEmpresa::cases())->mapWithKeys(fn (RolEmpresa $r) => [$r->value => $r->descripcion()])->all())
                ->default(RolEmpresa::Vendedor)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Nombre')->searchable()
                    ->description(fn (Membresia $record) => $record->user?->email),
                TextColumn::make('rol')->badge(),
                TextColumn::make('created_at')->label('Desde')->date('d/m/Y')->visibleFrom('md'),
            ])
            ->recordActions([ActionGroup::make([
                EditAction::make()
                    ->label('Cambiar rol')
                    ->before(function (Membresia $record, array $data, EditAction $action) {
                        $nuevo = $data['rol'] instanceof RolEmpresa ? $data['rol'] : RolEmpresa::from($data['rol']);
                        if ($record->rol === RolEmpresa::Admin && $nuevo !== RolEmpresa::Admin && static::esUltimoAdmin($record)) {
                            static::detener($action, 'La empresa debe tener al menos un administrador.');
                        }
                    }),
                DeleteAction::make()
                    ->label('Quitar acceso')
                    ->modalHeading('Quitar acceso a la empresa')
                    ->modalDescription('La persona dejará de ver esta empresa. Su cuenta y su historial en la bitácora se conservan.')
                    ->hidden(fn (Membresia $record) => $record->user_id === auth()->id())
                    ->before(function (Membresia $record, DeleteAction $action) {
                        if ($record->rol === RolEmpresa::Admin && static::esUltimoAdmin($record)) {
                            static::detener($action, 'No puedes quitar al único administrador.');
                        }
                    }),
            ])]);
    }

    public static function esUltimoAdmin(Membresia $m): bool
    {
        return ! Membresia::query()
            ->where('empresa_id', $m->empresa_id)
            ->where('rol', RolEmpresa::Admin)
            ->whereKeyNot($m->id)
            ->exists();
    }

    private static function detener($action, string $mensaje): void
    {
        Notification::make()->danger()->title($mensaje)->send();
        $action->halt();
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsuarios::route('/')];
    }
}
