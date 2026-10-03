<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compose file, when the user wrote it themselves.
 *
 * Null means the panel generates one from the image and port fields, which is
 * the common case. Stored as given rather than as the resolved document
 * `docker compose config` produces: the resolved form is normalised, expanded
 * and reordered, so storing it would hand someone back a file they did not
 * write and could not recognise as theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('compose')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('compose');
        });
    }
};
