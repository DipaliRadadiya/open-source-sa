<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A site the panel manages. One System User owns many applications.
     *
     * `settings` holds the answers to whatever fields the site type declared
     * (WordPress admin email, table prefix, …) — those differ per type, so
     * they are not columns. Anything the panel itself reasons about (runtime,
     * web root, git source) is a real column.
     *
     * `status` starts at `pending`: the record exists but nothing has been
     * written to the server yet.
     */
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('site_type');
            // php | node | static | proxy — what the web server has to serve.
            $table->string('serving_profile')->default('php');
            $table->string('status')->default('pending');

            // Runtime — only one applies, decided by the serving profile.
            $table->string('php_version')->nullable();
            $table->string('node_version')->nullable();
            $table->unsignedInteger('app_port')->nullable();
            // php | ssr | csr | static — how a git repository is served. Only
            // `ssr` runs a process; `csr` and `static` are built to files, and
            // `php` goes through the PHP stack like any other PHP site.
            $table->string('rendering_type')->nullable();

            $table->string('web_root')->default('/');
            $table->string('build_command', 500)->nullable();
            $table->string('start_command', 500)->nullable();

            // Git source. Nullable account = a public repository, which needs
            // no credentials at all.
            $table->foreignId('git_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('repository')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('branch')->nullable();

            // Type-specific answers, shaped by the site type's field schema.
            $table->json('settings')->nullable();

            // Provisioning progress: the steps completed so far (so the UI can
            // show which stage it reached), which step broke, and the
            // server-ops log reference for that failure.
            $table->json('steps')->nullable();
            $table->string('failed_step')->nullable();
            $table->string('reference')->nullable();

            // Last successful git deploy — the commit actually on disk, which
            // is the only honest answer to "what is running right now".
            $table->string('last_commit')->nullable();
            $table->timestamp('last_deployed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            // One app per port. Two apps bound to the same port means the
            // second silently never starts, and the reverse proxy sends its
            // traffic to the first — a data leak between sites, not just a
            // broken deploy.
            $table->unique('app_port');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
