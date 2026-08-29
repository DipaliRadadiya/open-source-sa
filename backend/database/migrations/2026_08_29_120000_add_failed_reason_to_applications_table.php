<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why provisioning failed, beside the step that failed and the log reference.
 *
 * `failed_step` says *where* and `reference` points at the server-ops log,
 * which between them answer most failures. They cannot answer the one that
 * matters most on a small server: a step killed by the kernel writes no
 * output, so the reference names an empty log and the panel can only report
 * that something stopped.
 *
 * Nullable and unclassified by default. A reason is set only where the exit
 * status genuinely identifies the cause — an invented category would send
 * someone to fix a thing that was never broken, which is worse than the
 * reference alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('failed_reason')->nullable()->after('failed_step');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('failed_reason');
        });
    }
};
