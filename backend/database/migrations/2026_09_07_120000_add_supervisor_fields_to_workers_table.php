<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fields supervisord exposes that a systemd unit had no place for.
     *
     * Workers move from systemd template units to supervisor programs, which
     * is what the commercial ServerAvatar panel has always used — so anyone
     * arriving from it finds the same screen, and a migrated box's existing
     * `[program:…]` blocks are the same format the panel now writes rather
     * than a second one to translate.
     *
     * Four of the five are supervisor's own directives with no systemd
     * equivalent worth emulating: `user`, `stdout_logfile`, `loglevel`, and a
     * raw passthrough. The fifth, `auto_start`, is supervisor's `autostart` —
     * distinct from the panel's `enabled`, which is whether the worker exists
     * at all. A worker can be defined and deliberately not started at boot.
     *
     * An additive migration rather than an edit to `create_workers_table`.
     * That file has already run on installed panels, and Laravel records a
     * migration by filename — editing it is invisible to every one of them.
     * Twice this week that cost a 500 no redeploy could fix.
     */
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table): void {
            // The account the processes run as. Null means the site's own
            // system user, which is the answer for every worker the panel
            // creates — it is a field because an adopted block may name
            // something else, and rewriting that silently would change who
            // owns the files a running job writes.
            $table->string('user')->nullable()->after('directory');

            // `stdout_logfile`. Null means the panel picks the site's own log
            // directory, which is where every other per-site log already goes.
            $table->string('log_file')->nullable()->after('user');

            // supervisor's own `loglevel`. Constrained in the request rather
            // than here: a check constraint on a string is a migration to
            // change every time supervisor adds a level.
            $table->string('log_level')->nullable()->after('log_file');

            // Appended verbatim to the program block, so a directive here wins
            // over everything the panel writes. Deliberately last, and the
            // same shape as `additional_directives` on PHP settings — the
            // escape hatch for the option the panel does not model yet.
            $table->text('extra_config')->nullable()->after('log_level');

            // supervisor's `autostart`: start with supervisord at boot. Not
            // the same question as `enabled`, which is whether the panel
            // manages this worker at all.
            $table->boolean('auto_start')->default(true)->after('auto_restart');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table): void {
            $table->dropColumn(['user', 'log_file', 'log_level', 'extra_config', 'auto_start']);
        });
    }
};
