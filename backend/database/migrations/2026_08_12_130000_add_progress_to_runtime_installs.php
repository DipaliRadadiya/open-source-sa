<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress for an install that takes minutes.
 *
 * The row already said *whether* something was installing; it could not say
 * how far along, so the screen showed a spinner for four minutes and an error
 * at the end with no clue which part failed.
 *
 * `output` is apt's own words, kept because it is the only thing that explains
 * a failure in terms the operator can act on — "unable to locate package" and
 * "could not get lock" both arrive as a failed install otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_installs', function (Blueprint $table) {
            // Derived from what apt actually printed, never guessed — see
            // InstallProgress. Nullable because a row that has produced no
            // recognisable output yet has no honest step to report.
            $table->string('current_step')->nullable()->after('status');
            // Bounded by InstallProgress::MAX_OUTPUT_BYTES on write: apt can
            // print thousands of lines, and only the tail is ever read.
            $table->text('output')->nullable()->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('runtime_installs', function (Blueprint $table) {
            $table->dropColumn(['current_step', 'output']);
        });
    }
};
