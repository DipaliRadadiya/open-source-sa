<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Set only on a staging site, pointing back at the production
            // site it was cloned from — this is the entire data model for
            // staging: no separate table, a staging site is just another
            // application row. `nullOnDelete` rather than cascading: deleting
            // production must not silently take its staging site with it,
            // the same "opt-in destruction" rule DeprovisionApplication uses.
            $table->foreignId('production_application_id')
                ->nullable()
                ->after('system_user_id')
                ->constrained('applications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_application_id');
        });
    }
};
