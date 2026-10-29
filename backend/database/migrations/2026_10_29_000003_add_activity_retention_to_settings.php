<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How long the activity log (and the panel's own finished task runs) are
     * kept, in months (2026-10-29, Dipali). Null is "keep everything" — the
     * default, so nothing is deleted on any server until someone picks a value.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('activity_retention_months')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('activity_retention_months');
        });
    }
};
