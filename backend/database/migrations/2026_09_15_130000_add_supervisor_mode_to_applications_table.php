<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records what is keeping an application's process alive.
 *
 * Until now the answer was always "our systemd unit", because the panel only
 * ever created applications itself. Adopting a server from the old panel
 * changes that: its applications are already running under a per-user PM2
 * daemon, and taking them over cannot mean restarting them — so the panel has
 * to be able to drive an application whose unit it does not own.
 *
 * Defaulted to `systemd` rather than left nullable. Every existing row *is*
 * systemd-supervised, that will not change, and a null here would mean "we do
 * not know how to stop this process", which is not a state worth being able to
 * represent.
 *
 * `pm2_process_name` is how the old daemon identifies the process — the name
 * passed to `pm2 start --name`, which is the application's name and not
 * anything the panel would otherwise derive. Null for everything the panel
 * runs itself.
 *
 * Additive, not an edit of the shipped `create`: a migration that has already
 * run reaches fresh installs only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('supervisor_mode', 16)
                ->default('systemd')
                ->after('process_instances');

            $table->string('pm2_process_name')
                ->nullable()
                ->after('supervisor_mode');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['supervisor_mode', 'pm2_process_name']);
        });
    }
};
