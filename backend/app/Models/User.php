<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'password', 'role', 'role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
            ->withPivot(['view', 'manage'])
            ->withTimestamps();
    }

    /**
     * The named permission bundle (Role & Permission management) assigned
     * to this user — separate from the `role` enum (admin/user coarse
     * gate). Only meaningful for non-admin users.
     */
    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function canView(string $permissionName): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasDirectPermission($permissionName, 'view')
            || $this->hasRolePermission($permissionName, 'view');
    }

    public function canManage(string $permissionName): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasDirectPermission($permissionName, 'manage')
            || $this->hasRolePermission($permissionName, 'manage');
    }

    private function hasDirectPermission(string $permissionName, string $ability): bool
    {
        return $this->permissions()
            ->where('name', $permissionName)
            ->wherePivot($ability, true)
            ->exists();
    }

    private function hasRolePermission(string $permissionName, string $ability): bool
    {
        if (! $this->role_id) {
            return false;
        }

        return $this->assignedRole->permissions()
            ->where('name', $permissionName)
            ->wherePivot($ability, true)
            ->exists();
    }
}
