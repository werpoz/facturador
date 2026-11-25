<?php

namespace App\Models;

use App\Enums\Permiso;
use App\Enums\RolEmpresa;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'es_superadmin' => 'boolean',
        ];
    }

    /** @var array<int, RolEmpresa|null> rol por empresa, para no consultar la base en cada verificación */
    private array $rolesEnCache = [];

    public function rolEn(Empresa $empresa): ?RolEmpresa
    {
        return $this->rolesEnCache[$empresa->id] ??= RolEmpresa::tryFrom(
            (string) $this->empresas()->whereKey($empresa->id)->value('rol')
        );
    }

    public function puede(Permiso $permiso, Empresa $empresa): bool
    {
        return (bool) $this->rolEn($empresa)?->puede($permiso);
    }

    public function olvidarRoles(): void
    {
        $this->rolesEnCache = [];
    }

    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class)->withPivot('rol')->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->empresas;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->empresas()->whereKey($tenant)->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->empresas()->first();
    }
}
