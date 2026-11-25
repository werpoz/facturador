<?php

namespace App\Filament\Resources\Usuarios\Pages;

use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Models\Membresia;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;

class ManageUsuarios extends ManageRecords
{
    protected static string $resource = UsuarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Agregar usuario')
                ->modalHeading('Agregar usuario a la empresa')
                ->using(function (array $data): Membresia {
                    $empresa = Filament::getTenant();
                    $email = mb_strtolower(trim($data['email']));
                    $user = User::query()->where('email', $email)->first();

                    if ($user && $empresa->membresias()->where('user_id', $user->id)->exists()) {
                        throw ValidationException::withMessages([
                            'mountedActions.0.data.email' => 'Ese usuario ya tiene acceso a esta empresa.',
                        ]);
                    }

                    if (! $user) {
                        if (blank($data['nombre'] ?? null) || blank($data['password'] ?? null)) {
                            throw ValidationException::withMessages([
                                'mountedActions.0.data.nombre' => 'El usuario es nuevo: escribe su nombre y una contraseña inicial.',
                            ]);
                        }
                        $user = User::create(['name' => $data['nombre'], 'email' => $email, 'password' => $data['password']]);
                    }

                    return $empresa->membresias()->create(['user_id' => $user->id, 'rol' => $data['rol']]);
                }),
        ];
    }
}
