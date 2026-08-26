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

            // Set only on a staging site, pointing back at the production site
            // it was cloned from — this is the entire data model for staging:
            // no separate table, a staging site is just another application
            // row. `nullOnDelete` rather than cascading: deleting production
            // must not silently take its staging site with it, the same
            // "opt-in destruction" rule DeprovisionApplication uses.
            $table->foreignId('production_application_id')
                ->nullable()
                ->constrained('applications')
                ->nullOnDelete();

            // Informational only, unlike `production_application_id` — a clone
            // has no ongoing relationship to its source, nothing reads this to
            // decide behavior. It only answers "where did this come from" on
            // the clone's own dashboard.
            $table->foreignId('cloned_from_application_id')
                ->nullable()
                ->constrained('applications')
                ->nullOnDelete();

            $table->string('name');
            // Stable, filesystem-safe identity, used to name the web-server
            // config. The domain is not usable for that: it is mutable and not
            // unique, so two applications could claim one domain and silently
            // overwrite each other's vhost. Both columns are unique — the name
            // because the user identifies a site by it, and the slug because
            // two names can slug to the same string ("My Blog" / "my-blog"),
            // which would put two sites in one file.
            $table->string('slug')->nullable();
            $table->string('domain')->nullable();
            $table->string('site_type');
            // php | node | static | proxy — what the web server has to serve.
            $table->string('serving_profile')->default('php');
            $table->string('status')->default('pending');
            // Taken offline without being deleted — a vhost swap to a small
            // "unavailable" page, reversible. Null means serving normally.
            $table->timestamp('disabled_at')->nullable();

            // Runtime — only one applies, decided by the serving profile.
            $table->string('php_version')->nullable();
            // Null means this site still uses the server-wide PHP pool.
            $table->timestamp('isolated_at')->nullable();
            $table->string('node_version')->nullable();
            $table->unsignedInteger('app_port')->nullable();
            // php | ssr | csr | static — how a git repository is served. Only
            // `ssr` runs a process; `csr` and `static` are built to files, and
            // `php` goes through the PHP stack like any other PHP site.
            $table->string('rendering_type')->nullable();

            $table->string('web_root')->default('/');
            $table->string('build_command', 500)->nullable();
            // The commands a deploy runs after the code is checked out.
            // `build_command` is one line, which covers `npm run build` and
            // nothing else; a real deployment needs a sequence — install
            // dependencies, build assets, migrate, restart workers — and it
            // has to be the user's to edit. Falls back to `build_command` when
            // unset.
            $table->text('deploy_script')->nullable();
            $table->string('start_command', 500)->nullable();
            // npm | yarn | pnpm | bun — only meaningful for the ssr/csr
            // rendering types a git-deployed Node app can have.
            $table->string('package_manager')->nullable();

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
            $table->timestamp('provisioning_started_at')->nullable();
            $table->string('reference')->nullable();

            // Last successful git deploy — the commit actually on disk, which
            // is the only honest answer to "what is running right now".
            $table->string('last_commit')->nullable();
            $table->timestamp('last_deployed_at')->nullable();
            $table->unsignedBigInteger('directory_size_bytes')->nullable();
            $table->timestamp('directory_size_updated_at')->nullable();

            // Deploy-on-push. `webhook_identifier` is the public part, and only
            // names the application — it is not a credential, so it can sit in
            // a URL. `webhook_secret` is the credential and is encrypted at
            // rest. `webhook_provider` is stored rather than sniffed from the
            // request: the three providers sign differently, and letting a
            // caller's own headers choose which check runs is letting them pick
            // the weakest one.
            $table->boolean('webhook_enabled')->default(false);
            $table->string('webhook_provider')->nullable();
            $table->string('webhook_identifier')->nullable()->unique();
            $table->text('webhook_secret')->nullable();
            $table->timestamp('webhook_last_delivered_at')->nullable();

            // Per-site protection. Every default here is what a site does
            // today with none of it configured: nothing blocked, nothing
            // prompted for.
            //
            // Whole-site HTTP Basic Auth — a login prompt in front of the
            // site, not a panel-level permission. The password is a bcrypt
            // hash written straight into the site's .htpasswd file: never the
            // plaintext, and never decrypted back to it.
            $table->boolean('basic_auth_enabled')->default(false);
            $table->string('basic_auth_username')->nullable();
            $table->string('basic_auth_password')->nullable();
            // allow_all | block_training | block_all — see App\Enums\AiBotPolicy.
            $table->string('ai_bot_policy')->default('allow_all');
            $table->boolean('waf_enabled')->default(false);
            // detect | enforce — see App\Enums\WafMode.
            $table->string('waf_mode')->default('detect');
            // Which of the six rule categories are active. Null means "all of
            // them" — a site that has never touched this screen gets full
            // coverage the moment it is turned on, not an empty set.
            $table->json('waf_categories')->nullable();
            // Per-application fail2ban stores raw INI, not structured
            // maxretry/findtime/bantime knobs: the jail and filter are the
            // things fail2ban actually reads, and rendering them from columns
            // only ever lost detail.
            $table->boolean('fail2ban_enabled')->default(false);
            $table->string('fail2ban_jail_name')->nullable();
            $table->longText('fail2ban_jail_content')->nullable();
            $table->longText('fail2ban_filter_content')->nullable();

            $table->timestamps();

            $table->unique('name');
            $table->unique('slug');
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
