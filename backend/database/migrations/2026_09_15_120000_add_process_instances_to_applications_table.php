<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many processes an application's unit runs.
 *
 * Until now the answer was always one, and the column's absence said so. An
 * application that wants to use more than one core has three ways to get
 * there and only one of them keeps the unit's guarantees: `pm2-runtime` as the
 * unit's `ExecStart`, which forks workers through Node's `cluster` module
 * while systemd keeps the boot hook, the cgroup and the memory ceiling.
 *
 * Null and 1 both mean "one process, plain `node`" — the shipped behaviour,
 * which is why this is nullable rather than defaulted to 1. A default would
 * write a number into every existing row to describe something none of them
 * ever chose.
 *
 * Deliberately *not* `instances: 'max'`, which is what the old panel used.
 * `max` reads the host's core count at start time, so on an 8-core box every
 * application claims eight workers and the per-app `MemoryMax` stops meaning
 * anything. The number is allocated by the panel and stored here.
 *
 * Additive, not an edit of the shipped `create`: changing a migration that has
 * already run reaches fresh installs only, and every existing panel would keep
 * a table without this column forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->unsignedSmallInteger('process_instances')
                ->nullable()
                ->after('start_command');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('process_instances');
        });
    }
};
