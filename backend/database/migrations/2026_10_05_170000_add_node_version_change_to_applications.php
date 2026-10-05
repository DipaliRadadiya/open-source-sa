<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a site's Node version change stands (junior re-test #12).
 *
 * The switch runs in a queued job — restart, then wait for the application to
 * answer — so the screen needs somewhere to read "switching to 22" and, when
 * the application would not start on it, why it was switched back. All three
 * are empty when nothing is in flight and the last change, if any, worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('node_version_target', 20)->nullable()->after('node_version');
            $table->string('node_version_failed_reason', 40)->nullable()->after('node_version_target');
            $table->string('node_version_failed_reference', 64)->nullable()->after('node_version_failed_reason');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['node_version_target', 'node_version_failed_reason', 'node_version_failed_reference']);
        });
    }
};
