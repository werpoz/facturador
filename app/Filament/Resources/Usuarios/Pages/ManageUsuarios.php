<?php

namespace App\Filament\Resources\Usuarios\Pages;

use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Models\Membresia;
use App\Models\User;
use App\Notifications\InvitacionUsuario;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
                ->successNotificationTitle('Usuario agregado. Si es nuevo, recibirá un correo para crear su contraseña.')
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
                        if (blank($data['nombre'] ?? null)) {
                            throw ValidationException::withMessages([
                                'mountedActions.0.data.nombre' => 'El usuario es nuevo: escribe su nombre.',
                            ]);
                        }

                        // La cuenta nace con una contraseña aleatoria que nadie conoce. El dueño del correo
                        // crea la suya desde el enlace que recibe: así un administrador no puede "preparar"
                        // una cuenta ajena con una clave que él sepa y esperar a que otra empresa la agregue.
                        $user = User::create(['name' => $data['nombre'], 'email' => $email, 'password' => Str::password(40)]);

                        $token = Password::broker(Filament::getAuthPasswordBroker())->createToken($user);
                        $user->notify(new InvitacionUsuario(Filament::getResetPasswordUrl($token, $user), $empresa->getFilamentName()));
                    }

                    return $empresa->membresias()->create(['user_id' => $user->id, 'rol' => $data['rol']]);
                }),
        ];
    }
}
