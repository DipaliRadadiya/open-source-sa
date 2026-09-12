<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per security update the panel was asked to install.
 *
 * Only panel-initiated runs. apt's own timer installs updates without telling
 * anyone, and its runs are read back out of unattended-upgrades' log by the
 * `updates` settings group — so there is no `trigger` column here, because it
 * could only ever hold one value.
 *
 * A row rather than a panel-owned log file. apt already writes two logs and
 * rotates them; a third file in that root:adm directory would mean owning
 * rotation and a `sudo tail` read path, and fragile log reading is exactly what
 * made a failed automatic update report nothing for months. `output` carries
 * the bounded, redacted tail tied to this run, and the full text stays in apt's
 * own files.
 *
 * Modelled on `runtime_installs`, which has been doing the same job for
 * `apt install php8.x-fpm` — a long privileged apt operation that restarts
 * services — since July.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_update_runs', function (Blueprint $table): void {
            $table->id();
            // Who asked. Nullable and null-on-delete: the run is a fact about
            // the server and outlives the account that started it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            // Classified failure code, never raw stderr — the detail is in
            // `output`. Same split the panel update uses.
            $table->string('reason')->nullable();
            // The server-ops reference, so a failure in the panel's own log can
            // be found from what the screen showed.
            $table->string('reference')->nullable();
            // unattended-upgrades' exit status. Null when the process never ran
            // at all — a sudo refusal or a timeout — which is not the same as 0
            // and must not be read as one.
            $table->integer('exit_code')->nullable();
            // How many packages it reported upgrading. Null means the output
            // did not say, which is different from none.
            $table->unsignedInteger('packages_upgraded')->nullable();
            // Bounded, redacted tail of the run's output.
            $table->text('output')->nullable();
            // Whether the box wants a restart now that this finished. Read
            // after the run, because that is when the flag appears.
            $table->boolean('reboot_required_after')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The screen asks one question on every page load — "is a run in
            // flight, and what was the last one?" — and both are answered by
            // ordering on this.
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_update_runs');
    }
};
