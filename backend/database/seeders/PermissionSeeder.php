<?php

namespace Database\Seeders;

use App\Services\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Writes the permission catalog and the protected Administrator role.
 *
 * The catalog itself lives in App\Services\PermissionCatalog, not here — the
 * deploy, the admin sync button and first-admin registration all need it, and
 * a seeder is only reachable from one of those three.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionCatalog::class)->sync();
    }
}
