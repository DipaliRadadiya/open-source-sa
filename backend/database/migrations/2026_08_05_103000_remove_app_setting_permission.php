<?php

use App\Models\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * `app_setting` was seeded for a Settings screen that was never built —
     * everything it would have held (name/web root edit, delete) already
     * lives on the Applications resource itself, and enable/disable landed
     * directly on the Dashboard. Cascades to `permission_role`, so no role
     * is left holding a grant for a permission that no longer exists.
     */
    public function up(): void
    {
        Permission::where('name', 'app_setting')->delete();
    }

    /**
     * Restores the row so `PermissionSeeder` can re-grant it to the
     * Administrator role on the next deploy; not a role assignment itself.
     */
    public function down(): void
    {
        Model::unguarded(function (): void {
            Permission::create([
                'name' => 'app_setting',
                'level' => 'application',
                'sub_level' => 'application',
                'title' => 'Settings',
                'icon' => 'settings',
                'url' => '/settings',
                'order' => 8,
            ]);
        });
    }
};
