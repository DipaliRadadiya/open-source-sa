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
            // Where a Node version change stands: the switch runs in a queued job
            // (restart, then wait for the application to answer), so the screen
            // needs somewhere to read "switching to 22" and, when the application
            // would not start on it, why it was switched back. All three are empty
            // when nothing is in flight and the last change, if any, worked.
            $table->string('node_version_target', 20)->nullable();
            $table->string('node_version_failed_reason', 40)->nullable();
            $table->string('node_version_failed_reference', 64)->nullable();
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
            // How many processes the unit runs, through `pm2-runtime` and Node's
            // `cluster` module while systemd keeps the boot hook, the cgroup and
            // the memory ceiling. Null and 1 both mean "one process, plain `node`",
            // which is why this is nullable rather than defaulted to 1. Deliberately
            // not `instances: 'max'`: that reads the host's core count, so every
            // application would claim every core and `MemoryMax` stops meaning
            // anything. The number is allocated by the panel.
            $table->unsignedSmallInteger('process_instances')->nullable();
            // What keeps the process alive: `systemd` (the panel's own unit) or
            // `pm2` for an application adopted from the old panel, which already
            // runs under a per-user PM2 daemon and cannot be restarted to take it
            // over. Not nullable: "we do not know how to stop this process" is not
            // a state worth representing. `pm2_process_name` is the name passed to
            // `pm2 start --name`; null for everything the panel runs itself.
            $table->string('supervisor_mode', 16)->default('systemd');
            $table->string('pm2_process_name')->nullable();
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
            // The installer's passwords (admin_password, mailer_password), kept out
            // of `settings` — a plain JSON column the API returns to anyone allowed
            // to view the site. Encrypted by the model's cast, held only until the
            // install succeeds.
            $table->text('install_secrets')->nullable();

            // Provisioning progress: the steps completed so far (so the UI can
            // show which stage it reached), which step broke, and the
            // server-ops log reference for that failure.
            $table->json('steps')->nullable();
            $table->string('failed_step')->nullable();
            // Why provisioning failed, beside the step and the log reference. A
            // step killed by the kernel writes no output, so the reference names
            // an empty log. Nullable and unclassified by default: a reason is set
            // only where the exit status genuinely identifies the cause.
            $table->string('failed_reason')->nullable();
            $table->timestamp('provisioning_started_at')->nullable();
            $table->string('reference')->nullable();

            // Last successful git deploy — the commit actually on disk, which
            // is the only honest answer to "what is running right now".
            $table->string('last_commit')->nullable();
            $table->timestamp('last_deployed_at')->nullable();
            $table->unsignedBigInteger('directory_size_bytes')->nullable();
            // How much of `directory_size_bytes` (the site's TOTAL: document root
            // plus Docker volumes) is in volumes, so the total is explainable. Null
            // means "no volumes to measure" — every PHP, Node and static site —
            // while 0 is a container site whose volumes are genuinely empty.
            $table->unsignedBigInteger('volume_size_bytes')->nullable();
            // The release now live, and the path of the one before it. The foreign
            // key is added by the releases migration: `releases` points back at
            // this table, so it cannot exist yet.
            $table->unsignedBigInteger('current_release_id')->nullable();
            $table->string('previous_release_path')->nullable();
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
            // The provider's own id for the webhook the panel registered (a GitHub
            // or GitLab hook id, a Bitbucket hook uuid), so it can update the
            // secret on rotation and remove the hook later. Null means the panel
            // did not register one.
            $table->string('webhook_remote_id')->nullable();
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
            // Container sites.
            //
            // The image reference as the user gave it, tag or digest included.
            // Stored verbatim rather than split into name/tag: a reference can
            // carry a registry host, a port, a namespace, a tag AND a digest, and a
            // schema that takes it apart has to put it back together identically
            // or it is pulling something else.
            $table->string('image')->nullable();
            // Which stored credential to pull the image with. Null is the common
            // case and means "anonymous", not "broken". `nullOnDelete`: deleting a
            // registry must not be blocked by a site that used it, and cascading
            // would remove the site over a credential change.
            $table->foreignId('registry_id')->nullable()->constrained('registries')->nullOnDelete();
            // The port INSIDE the container. Distinct from `app_port`, which is the
            // loopback port on the host that the panel allocated and nginx proxies
            // to. Conflating them is how you get a container published on a port
            // another application already holds.
            $table->unsignedInteger('container_port')->nullable();
            // Ceilings, so one container cannot take the box down and the panel
            // with it. Nullable with no default: null means "the configured default
            // at render time", and a default here would cap every existing site.
            // Strings, as Docker takes them: '512m', '2g'; cores as '0.5', '1.5'.
            $table->string('memory_limit')->nullable();
            $table->string('cpu_limit', 16)->nullable();
            // The compose file, when the user wrote it themselves. Null means the
            // panel generates one from the fields above. Stored as given rather
            // than as the resolved `docker compose config` output, which is
            // normalised and reordered beyond recognition.
            $table->text('compose')->nullable();
            // The network the container joins. Null means Docker's default bridge.
            // Not a foreign key: Docker owns these objects, and a network removed
            // with `docker network rm` has to be reportable rather than impossible.
            $table->string('docker_network')->nullable();
            // `[{"volume": "name", "path": "/var/lib/mysql"}, …]` — a volume is
            // only meaningful together with the path it is mounted at.
            $table->json('volume_mounts')->nullable();
            // The secrets a one-click Docker app was installed with, so a
            // re-render reuses them rather than rotating a password in one half of
            // the compose file only. `encrypted:array` at rest.
            $table->text('docker_secrets')->nullable();
            // Set when somebody confirms they saved the generated credentials —
            // by an explicit acknowledgement, never by rendering them.
            $table->timestamp('credentials_seen_at')->nullable();
            // The id the Log Monitoring Suite addon (formerly InsightHub) gave the
            // site; every report is asked by it.
            $table->unsignedBigInteger('log_monitoring_suite_id')->nullable();
            // Where a simple-mode container sees the site's own directory. Null
            // means `/app`, which is what older sites were created with; new sites
            // get `ContainerMountPath::SITE_MOUNT`.
            $table->string('site_mount_path')->nullable();
            // What the last deploy's readiness check saw (DS-03): `running`,
            // `restarting`, `exited` or `not_answering`, null if none has run —
            // and `{reason, params, log, at}` for the last failure. `last_failure`
            // is encrypted at rest (DS-08): it holds the container's own log lines,
            // which on first boot print connection strings and tokens. `text`, not
            // `json`: ciphertext is not JSON, and MySQL/MariaDB check a json column.
            $table->string('container_status', 32)->nullable();
            $table->text('last_failure')->nullable();
            // A second loopback port, for an application whose own process listens
            // on one more than `app_port` (FS-C22 #8: n8n's task-runner broker).
            // Allocated from the same range and unique for the same reason.
            $table->unsignedInteger('internal_port')->nullable()->unique();

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
