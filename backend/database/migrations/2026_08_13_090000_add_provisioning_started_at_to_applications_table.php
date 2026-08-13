<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the current provisioning run started.
 *
 * `created_at` was standing in for this, and it is wrong the moment anyone
 * presses Retry: the row was created once, the run started again, and a screen
 * doing `now - created_at` reports an elapsed time that keeps climbing across
 * attempts. The frontend was showing "usually takes a few minutes" rather than
 * a number it knew to be false, which is the right call and a bad place to
 * leave them.
 *
 * Additive rather than folded into the create migration: the shared dev
 * database has the frontend's live data, so `migrate:fresh` is not available
 * here. Same reasoning as the two additive migrations from 2026-08-12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('provisioning_started_at')->nullable()->after('failed_step');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('provisioning_started_at');
        });
    }
};
