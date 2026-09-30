<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-container CPU quotas, and per-database limits of both kinds.
 *
 * **Everything added here is nullable with no default, and that is the whole
 * design.** `applications.memory_limit` already works this way — null means "fall
 * back to the configured default at render time" — and copying that shape was
 * deliberate rather than convenient. A default on a new constraint column is not a
 * default for new rows: it is a value asserted retroactively about every row that
 * already exists, so `cpu_limit` defaulting to anything would cap every container
 * site on the box the next time it deployed. The symptom would be unexplained
 * slowness arriving through a feature nobody enabled, which is close to
 * undiagnosable.
 *
 * So the templates emit `cpus:` only when a value is set, and a site with no CPU
 * limit renders byte-for-byte the file it rendered before this column existed.
 *
 * **Strings, not numbers.** `cpu_limit` holds '0.5' and '1.5' — a decimal the
 * validator bounds to two places — and `memory_limit` holds Docker's own shorthand
 * ('512m', '2g'). Storing CPU as a float would invite the value being reformatted
 * on the way through; storing memory as bytes would mean the panel showing a number
 * nobody typed. Both are handed to Compose as written, so they are kept as written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            // Cores, as Docker counts them: '1' is one full core, '0.5' is half of
            // one. Beside `memory_limit`, which has been per-application since the
            // container stack shipped — this is the missing half of that pair.
            $table->string('cpu_limit', 16)->nullable()->after('memory_limit');
        });

        Schema::table('docker_databases', function (Blueprint $table): void {
            // Databases had NEITHER as a per-instance field: both came from one
            // server-wide config value, so every engine on the box got the same
            // 512m whether it was a Redis cache or the primary Postgres. Sizes are
            // the product here, so they belong on the row.
            $table->string('cpu_limit', 16)->nullable()->after('docker_network');
            $table->string('memory_limit', 20)->nullable()->after('cpu_limit');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('cpu_limit');
        });

        Schema::table('docker_databases', function (Blueprint $table): void {
            $table->dropColumn(['cpu_limit', 'memory_limit']);
        });
    }
};
