<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'password', 'is_admin', 'is_system', 'last_login_at', 'last_login_ip'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_system' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * A machine account (the central-panel integration) rather than a person: it owns API tokens,
     * never logs in and is not managed from the admin area — see resolveRouteBinding().
     */
    public function isSystem(): bool
    {
        return $this->is_system;
    }

    /**
     * Only people are addressable as `/admin/users/{user}`.
     *
     * Enforced here, not per controller, so no user route can forget the guard: a machine account
     * is not a panel user, so every one of them answers 404.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->where('is_system', false)
            ->first();
    }

    /**
     * Coarse gate: whether the user can reach the admin area (`access-admin`). Distinct from the
     * granular permission system, which resolves from assigned Roles + direct grants.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /**
     * Named Roles assigned to this user. Effective permissions are the union across all of them.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function canView(string $permissionName): bool
    {
        return $this->hasAbility($permissionName, 'view');
    }

    public function canManage(string $permissionName): bool
    {
        return $this->hasAbility($permissionName, 'manage');
    }

    /**
     * Pure role-based resolution (no admin bypass): granted if ANY assigned role grants it.
     * `manage` implying `view` is enforced upstream when grants are written.
     */
    private function hasAbility(string $permissionName, string $ability): bool
    {
        foreach ($this->roles as $role) {
            $viaRole = $role->permissions->firstWhere('name', $permissionName);
            if ($viaRole && $viaRole->pivot->{$ability}) {
                return true;
            }
        }

        return false;
    }
}
