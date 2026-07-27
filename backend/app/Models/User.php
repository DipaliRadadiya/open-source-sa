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

#[Fillable(['name', 'username', 'password', 'is_admin'])]
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
        ];
    }

    /**
     * Coarse gate — whether the user can reach the admin area
     * (`access-admin`). Distinct from the granular permission system,
     * which resolves purely from the user's assigned Roles + direct grants.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /**
     * Named Roles assigned to this user (many-to-many). Effective
     * permissions are the deduped OR-union across all assigned roles.
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
     * Pure role-based resolution (no admin bypass): the ability is granted if
     * ANY assigned role grants it. `manage` implies `view` is enforced upstream
     * when grants are written.
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
