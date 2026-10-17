<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MySQL/MariaDB root password, when the user has set one (v7's "change
 * root password", 2026-10-17) or saved the one a migrated v7 server already
 * had.
 *
 * Separate from `password`, which belongs to the panel's own admin account
 * (`panel_…`): root is the user's account, not the panel's. Encrypted by the
 * model's cast; null means the panel knows no root password, which on a fresh
 * install is the normal state — root signs in through the socket as OS root.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_connections', function (Blueprint $table): void {
            $table->text('root_password')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('database_connections', function (Blueprint $table): void {
            $table->dropColumn('root_password');
        });
    }
};
