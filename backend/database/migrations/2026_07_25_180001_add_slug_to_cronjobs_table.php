<?php

use App\Models\Cronjob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            // Stable, unique identifier used for the /etc/cron.d filename.
            // Survives data migration/re-import (unlike the auto-increment id).
            // Always populated by the app on create; nullable at the DB level
            // only so the column can be added before backfilling.
            $table->string('slug')->nullable()->after('name');
        });

        // Backfill any existing rows with a unique slug derived from the name.
        Cronjob::query()->whereNull('slug')->get()->each(function (Cronjob $cronjob) {
            $cronjob->update(['slug' => Cronjob::uniqueSlug($cronjob->name, $cronjob->id)]);
        });

        Schema::table('cronjobs', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
