<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Informational only, unlike `production_application_id` — a
            // clone has no ongoing relationship to its source, nothing reads
            // this to decide behavior. It only answers "where did this come
            // from" on the clone's own dashboard. `nullOnDelete`: removing
            // the source must not touch a clone that has become its own
            // independent site.
            $table->foreignId('cloned_from_application_id')
                ->nullable()
                ->after('production_application_id')
                ->constrained('applications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cloned_from_application_id');
        });
    }
};
