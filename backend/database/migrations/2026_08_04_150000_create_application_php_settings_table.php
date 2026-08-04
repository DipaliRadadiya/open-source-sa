<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the user chose for one site's PHP. The pool file on disk is a
        // rendered artefact of this row, the same relationship the vhost has
        // with the application — we generate it, we never parse it back.
        //
        // Every column is nullable so that "not set" means "use the server
        // default" rather than a number the panel invented on the user's
        // behalf and then has to keep in step with PHP's own defaults.
        Schema::create('application_php_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('memory_limit')->nullable();
            $table->string('upload_max_filesize')->nullable();
            $table->string('post_max_size')->nullable();
            $table->unsignedInteger('max_execution_time')->nullable();
            $table->unsignedInteger('max_input_time')->nullable();
            $table->unsignedInteger('max_input_vars')->nullable();
            $table->unsignedInteger('session_gc_maxlifetime')->nullable();

            // Three pool knobs, not eight. RunCloud exposes exactly these and
            // derives the rest; the other five are computable from max_children
            // and are mostly a way to produce a pool that will not start.
            $table->string('pm_type')->nullable();          // ondemand | dynamic | static
            $table->unsignedSmallInteger('pm_max_children')->nullable();
            $table->unsignedInteger('pm_max_requests')->nullable();

            $table->boolean('open_basedir_enabled')->default(false);
            $table->text('disable_functions')->nullable();
            $table->boolean('allow_url_fopen')->nullable();
            $table->string('php_timezone')->nullable();
            $table->string('auto_prepend_file')->nullable();

            // The escape hatch CloudPanel and Forge both ship. No structured
            // field list survives contact with everyone's needs, and without
            // this the answer to "I need one more directive" is "edit the file
            // by hand and lose it on the next save".
            $table->text('additional_directives')->nullable();

            $table->timestamps();
        });

        Schema::table('applications', function (Blueprint $table) {
            // When this site got its own pool. Null means it is still sharing
            // the server-wide pool, which is what every site does today.
            $table->timestamp('isolated_at')->nullable()->after('php_version');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('isolated_at');
        });

        Schema::dropIfExists('application_php_settings');
    }
};
