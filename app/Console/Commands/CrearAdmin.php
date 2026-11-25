<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Crea (o promueve) al superadministrador: la persona que da de alta empresas.
 *
 *   php artisan facturador:crear-admin tu@correo.com --nombre="Tu Nombre"
 */
class CrearAdmin extends Command
{
    protected $signature = 'facturador:crear-admin {email} {--nombre=Administrador} {--password=}';

    protected $description = 'Crea el usuario superadministrador que puede registrar empresas';

    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));
        $user = User::query()->where('email', $email)->first();
        $password = null;

        if (! $user) {
            $password = $this->option('password') ?: Str::password(14, symbols: false);
            $user = User::create(['name' => $this->option('nombre'), 'email' => $email, 'password' => $password]);
        }

        $user->forceFill(['es_superadmin' => true])->save();

        $this->info("Superadministrador listo: {$email}");
        if ($password && ! $this->option('password')) {
            $this->warn("Contraseña generada (guárdala, no se volverá a mostrar): {$password}");
        }

        return self::SUCCESS;
    }
}
