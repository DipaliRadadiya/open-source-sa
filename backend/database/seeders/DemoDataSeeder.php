<?php

namespace Database\Seeders;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\ApplicationPhpSettings;
use App\Models\ApplicationWafRule;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Cronjob;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Deployment;
use App\Models\FirewallRule;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Demo system users and applications, for frontend work against a dev box.
 *
 * Deliberately NOT registered in DatabaseSeeder: a real install runs
 * `db:seed --class=PermissionSeeder` on every deploy, and nothing should be
 * able to pull fake sites in by accident. Run it by hand:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Every row is keyed on a unique column (`updateOrCreate`/`firstOrCreate`),
 * so the seeder is re-runnable and never touches a row it doesn't own —
 * everything here lives under the `.demo.test` domain suffix, so re-running
 * it to pick up newly added demo fields can never collide with anything a
 * real user entered. It shares a database with whoever is developing
 * against it.
 *
 * The rows are hand-picked rather than generated, because the point is to
 * exercise the branches the application sidebar actually takes:
 *
 *  - `serving_profile` php / node / static — decides which sections render
 *  - `start_command` set → ProcessSupervisor::runs() is true → the process
 *    and supervision entries appear (it reads the column, not the server,
 *    so no systemd unit needs to exist behind these)
 *  - `webhook_enabled` → the deploy-on-push entries
 *  - all four ApplicationStatus cases, so non-active rendering is covered
 *  - "Company Blog" has Basic Auth, the AI Bot Blocker, and the 8G Firewall
 *    all turned on (plus a staging counterpart, "Company Blog (Staging)") —
 *    "Legacy Uploads" has the Firewall in detect-only mode instead, and
 *    "Internal API" a different Bot Blocker tier, so those screens show
 *    more than one state rather than only ever the same one
 */
class DemoDataSeeder extends Seeder
{
    /** Prefix on every demo row, so they are trivial to find and delete. */
    private const DOMAIN_SUFFIX = '.demo.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DemoDataSeeder refuses to run in production — it writes fake sites.'
            );
        }

        $web = SystemUser::firstOrCreate(
            ['username' => 'demoweb'],
            ['home_path' => '/home/demoweb', 'shell' => '/bin/bash', 'sudo' => false, 'ssh_access' => true],
        );

        $apps = SystemUser::firstOrCreate(
            ['username' => 'demoapps'],
            ['home_path' => '/home/demoapps', 'shell' => '/bin/bash', 'sudo' => false, 'ssh_access' => false],
        );

        foreach ($this->applications($web->id, $apps->id) as $attributes) {
            // `updateOrCreate`, not `firstOrCreate`: every row here is the
            // seeder's own fake data (the `.demo.test` suffix), not anything
            // a real user entered, so re-running this to pick up newly
            // added demo fields (Basic Auth, the AI Bot Blocker, the 8G
            // Firewall) is exactly what re-running a demo seeder is for.
            $application = Application::updateOrCreate(
                ['domain' => $attributes['domain']],
                $attributes,
            );

            $this->completeApplication($application);
        }

        $this->seedStaging($web->id);
        $this->seedWafRules();
        $this->seedDatabases();
        $this->seedBackups();
        $this->seedWorkers();
        $this->seedCronjobs($web->id, $apps->id);
        $this->seedDeployments();
        $this->seedFirewallRules();
        $this->seedPhpSettings();

        $this->command?->info('Demo data seeded. Remove it with: Application::where("domain", "like", "%'.self::DOMAIN_SUFFIX.'")->delete()');
    }

    /**
     * The two things every application needs beyond its own row: a slug, which
     * names its web-server config, and a primary domain row, which is what the
     * Domains screen reads.
     *
     * Shared because the seeder builds applications in two places — the main
     * loop and the staging site — and demo data that behaves differently from
     * a real site is worse than none: it sends whoever is working against it
     * to debug a difference that does not exist in production.
     */
    private function completeApplication(Application $application): void
    {
        if (blank($application->slug)) {
            $application->forceFill([
                'slug' => Application::uniqueSlug((string) $application->name, $application->id),
            ])->save();
        }

        $application->domains()->updateOrCreate(
            ['domain' => $application->domain],
            [
                'type' => DomainType::Primary,
                'is_test' => ApplicationDomain::looksTemporary((string) $application->domain),
            ],
        );
    }

    /**
     * Databases feature: one WordPress site with a real Database +
     * DatabaseUser row, so the Databases screen (both the per-app card and
     * the server-wide list) has something in it.
     */
    private function seedDatabases(): void
    {
        $blog = Application::where('domain', 'blog'.self::DOMAIN_SUFFIX)->first();

        if ($blog === null) {
            return;
        }

        $database = Database::firstOrCreate(
            ['name' => 'demo_blog_db', 'engine' => 'mysql'],
            ['application_id' => $blog->id, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'size_bytes' => 18_874_368],
        );

        DatabaseUser::firstOrCreate(
            ['database_id' => $database->id, 'username' => 'demo_blog_user', 'host' => 'localhost'],
            ['password' => 'demo-db-password-not-real', 'connection_preference' => 'localhost'],
        );
    }

    /**
     * Backups feature: a storage destination + a backup target for "Company
     * Blog", plus a short history spanning the statuses the Backups screen
     * actually renders differently (verified, failed, running).
     */
    private function seedBackups(): void
    {
        $blog = Application::where('domain', 'blog'.self::DOMAIN_SUFFIX)->first();

        if ($blog === null) {
            return;
        }

        $destination = StorageDestination::firstOrCreate(
            ['name' => 'Demo S3 Bucket'],
            [
                'endpoint' => 'https://s3.us-east-1.amazonaws.com',
                'region' => 'us-east-1',
                'bucket' => 'demo-panel-backups',
                'prefix' => 'demo',
                'access_key' => 'DEMOACCESSKEYNOTREAL',
                'secret_key' => 'demo-secret-key-not-real',
            ],
        );

        $target = BackupTarget::firstOrCreate(
            ['application_id' => $blog->id],
            [
                'storage_destination_id' => $destination->id,
                'type' => BackupType::Full,
                'retention_count' => 7,
                'file_excludes' => ['wp-content/cache/', '.git/'],
                'database_excludes' => [],
                'enabled' => true,
                'frequency' => 'daily',
                'last_run_at' => now()->subDay(),
            ],
        );

        $history = [
            ['status' => BackupStatus::Verified, 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()->addMinutes(4), 'verified_at' => now()->subDay()->addMinutes(5), 'size_bytes' => 41_943_040],
            ['status' => BackupStatus::Failed, 'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)->addMinutes(1), 'reason' => 'storage destination unreachable'],
            ['status' => BackupStatus::Running, 'started_at' => now()->subMinutes(3), 'finished_at' => null],
        ];

        foreach ($history as $index => $attributes) {
            Backup::firstOrCreate(
                ['backup_target_id' => $target->id, 'reference' => 'demo-backup-'.($index + 1)],
                array_merge([
                    'application_id' => $blog->id,
                    'type' => BackupType::Full,
                    'is_safety' => false,
                ], $attributes),
            );
        }
    }

    /**
     * Workers feature: a queue worker on the git-deployed app — the one
     * demo row where `ProcessSupervisor::runs()` and a real worker list
     * both have something to show.
     */
    private function seedWorkers(): void
    {
        $api = Application::where('domain', 'api'.self::DOMAIN_SUFFIX)->first();

        if ($api === null) {
            return;
        }

        Worker::firstOrCreate(
            ['application_id' => $api->id, 'name' => 'queue-worker'],
            [
                'command' => 'php artisan queue:work --sleep=3 --tries=3',
                'kind' => Worker::KIND_QUEUE,
                'processes' => 2,
                'auto_restart' => true,
                'restart_on_deploy' => true,
                'enabled' => true,
            ],
        );
    }

    /**
     * Cronjobs are server-level, not per-app — `system_user_id` only, no
     * `application_id` (that gap is still open; see the 2026-07-27 memory
     * note). One active job, one disabled, on different demo system users.
     */
    private function seedCronjobs(int $webUserId, int $appsUserId): void
    {
        $jobs = [
            ['name' => 'Nightly backup verification', 'username' => 'demoweb', 'system_user_id' => $webUserId, 'command' => '/usr/bin/php /home/demoweb/verify-backups.php', 'expression' => '0 2 * * *', 'active' => true],
            ['name' => 'Disk cache cleanup', 'username' => 'demoapps', 'system_user_id' => $appsUserId, 'command' => 'find /tmp/app-cache -mtime +7 -delete', 'expression' => '30 3 * * 0', 'active' => false],
        ];

        foreach ($jobs as $attributes) {
            Cronjob::firstOrCreate(
                ['name' => $attributes['name']],
                array_merge($attributes, ['slug' => Cronjob::uniqueSlug($attributes['name'])]),
            );
        }
    }

    /**
     * Deployment history for "Internal API" — the only demo row with a
     * repository — spanning the states the Deployments screen renders
     * differently (succeeded, failed, in progress).
     */
    private function seedDeployments(): void
    {
        $api = Application::where('domain', 'api'.self::DOMAIN_SUFFIX)->first();

        if ($api === null) {
            return;
        }

        $runs = [
            ['status' => DeploymentStatus::Succeeded, 'commit_hash' => '9f2c1ab4d7e35608b1c0f4a29d8e7b3c5a1049fe', 'commit_message' => 'Bump dependencies', 'started_at' => now()->subHours(6), 'finished_at' => now()->subHours(6)->addMinutes(2)],
            ['status' => DeploymentStatus::Failed, 'commit_hash' => '3ab7f10c9e2d4b6a8f1c5d3e7b9a2c4f6d8e0b1a', 'commit_message' => 'Add search endpoint', 'failed_step' => 'run_migrations', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()->addMinutes(1)],
            ['status' => DeploymentStatus::Running, 'commit_hash' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0', 'commit_message' => 'Refactor auth middleware', 'started_at' => now()->subMinutes(1), 'finished_at' => null],
        ];

        foreach ($runs as $index => $attributes) {
            Deployment::firstOrCreate(
                ['application_id' => $api->id, 'commit_hash' => $attributes['commit_hash']],
                array_merge([
                    'trigger' => DeploymentTrigger::Manual,
                    'branch' => 'main',
                    'commit_author' => 'demo-dev',
                    'reference' => 'demo-deploy-'.($index + 1),
                ], $attributes),
            );
        }
    }

    /**
     * Server-level Firewall (UFW): a couple of rules so the screen isn't
     * empty on a fresh dev box, including one system-seeded (not user-made)
     * row to exercise the delete-protection badge.
     */
    private function seedFirewallRules(): void
    {
        FirewallRule::firstOrCreate(
            ['port_from' => 22],
            ['protocol' => 'tcp', 'action' => 'allow', 'description' => 'SSH', 'origin' => 'default', 'enabled' => true],
        );

        FirewallRule::firstOrCreate(
            ['port_from' => 51820],
            ['protocol' => 'udp', 'action' => 'allow', 'source_ip' => '203.0.113.10', 'description' => 'Office VPN', 'origin' => 'user', 'enabled' => true],
        );
    }

    /**
     * PHP Settings: one demo app with an explicit tuning row, rather than
     * always falling back to `ApplicationPhpSettings::defaults()`.
     */
    private function seedPhpSettings(): void
    {
        $blog = Application::where('domain', 'blog'.self::DOMAIN_SUFFIX)->first();

        if ($blog === null) {
            return;
        }

        ApplicationPhpSettings::firstOrCreate(
            ['application_id' => $blog->id],
            [
                'memory_limit' => '256M',
                'upload_max_filesize' => '64M',
                'post_max_size' => '64M',
                'max_execution_time' => 60,
                'pm_type' => 'dynamic',
                'pm_max_children' => 10,
                'open_basedir_enabled' => true,
            ],
        );
    }

    /**
     * A staging site for "Company Blog" — the only demo row that exercises
     * `production_application_id` and the Staging Area screen.
     */
    private function seedStaging(int $webUserId): void
    {
        $production = Application::where('domain', 'blog'.self::DOMAIN_SUFFIX)->first();

        if ($production === null) {
            return;
        }

        $staging = Application::updateOrCreate(
            ['domain' => 'staging-blog'.self::DOMAIN_SUFFIX],
            [
                'system_user_id' => $webUserId,
                'production_application_id' => $production->id,
                'name' => 'Company Blog (Staging)',
                'site_type' => 'wordpress',
                'serving_profile' => 'php',
                'rendering_type' => 'php',
                'status' => 'active',
                'php_version' => '8.4',
                'web_root' => '/',
            ],
        );

        // Same as every other demo site — this one is created here rather than
        // in the main loop, which is exactly how it would get missed.
        $this->completeApplication($staging);
    }

    /**
     * Exceptions/custom rules for "Company Blog" — the only demo row with
     * the 8G Firewall on, so its Exceptions/Custom rules lists have
     * something in them rather than rendering empty.
     */
    private function seedWafRules(): void
    {
        $blog = Application::where('domain', 'blog'.self::DOMAIN_SUFFIX)->first();

        if ($blog === null) {
            return;
        }

        ApplicationWafRule::firstOrCreate(
            ['application_id' => $blog->id, 'type' => 'exception', 'value' => 'mobiquo'],
        );

        ApplicationWafRule::firstOrCreate(
            ['application_id' => $blog->id, 'type' => 'block', 'value' => 'old-staging-backup'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function applications(int $webUserId, int $appsUserId): array
    {
        return [
            // ── PHP profile ──────────────────────────────────────────────
            [
                'system_user_id' => $webUserId,
                'name' => 'Company Blog',
                'domain' => 'blog'.self::DOMAIN_SUFFIX,
                'site_type' => 'wordpress',
                'serving_profile' => 'php',
                'rendering_type' => 'php',
                'status' => 'active',
                'php_version' => '8.4',
                'web_root' => '/',
                // The flagship demo row — every feature shipped this week
                // turned on, so none of their screens render empty.
                'basic_auth_enabled' => true,
                'basic_auth_username' => 'preview',
                'basic_auth_password' => Hash::make('demo-preview-2026'),
                'ai_bot_policy' => 'block_training',
                'waf_enabled' => true,
                'waf_mode' => 'enforce',
                'waf_categories' => ['query_string', 'request_uri', 'user_agent'],
            ],
            [
                'system_user_id' => $webUserId,
                'name' => 'Legacy Uploads',
                'domain' => 'legacy'.self::DOMAIN_SUFFIX,
                'site_type' => 'php',
                'serving_profile' => 'php',
                'rendering_type' => 'php',
                'status' => 'active',
                'php_version' => '8.3',
                'web_root' => '/public',
                // Detect-only, not enforce — shows the "just watch" state
                // the 8G Firewall recommends starting new sites on.
                'waf_enabled' => true,
                'waf_mode' => 'detect',
                'waf_categories' => ['request_uri', 'method'],
            ],

            // Git-deployed, with deploy-on-push enabled — the only row that
            // should show the webhook and deployment-history entries.
            [
                'system_user_id' => $webUserId,
                'name' => 'Internal API',
                'domain' => 'api'.self::DOMAIN_SUFFIX,
                'site_type' => 'git',
                'serving_profile' => 'php',
                'rendering_type' => 'php',
                'status' => 'active',
                'php_version' => '8.4',
                'web_root' => '/public',
                'repository' => 'acme/internal-api',
                'repository_url' => 'https://github.com/acme/internal-api.git',
                'branch' => 'main',
                'build_command' => 'composer install --no-dev --optimize-autoloader',
                'last_commit' => '9f2c1ab4d7e35608b1c0f4a29d8e7b3c5a1049fe',
                'last_deployed_at' => now()->subHours(6),
                'webhook_enabled' => true,
                'webhook_provider' => 'github',
                'webhook_identifier' => 'demo-internal-api',
                'webhook_secret' => 'demo-webhook-secret-not-a-real-one',
                // A different AI Bot Blocker state than the WordPress row —
                // shows the "block everything, including citation traffic"
                // tier actually looks different from "block training only".
                'ai_bot_policy' => 'block_all',
            ],

            // ── Node profile — start_command drives has_process ──────────
            [
                'system_user_id' => $appsUserId,
                'name' => 'Status Page',
                'domain' => 'status'.self::DOMAIN_SUFFIX,
                'site_type' => 'uptimekuma',
                'serving_profile' => 'node',
                'rendering_type' => 'ssr',
                'status' => 'active',
                'node_version' => '22',
                'app_port' => 3001,
                'start_command' => 'node server/server.js',
            ],
            [
                'system_user_id' => $appsUserId,
                'name' => 'Automation',
                'domain' => 'flows'.self::DOMAIN_SUFFIX,
                'site_type' => 'n8n',
                'serving_profile' => 'node',
                'rendering_type' => 'ssr',
                'status' => 'active',
                'node_version' => '22',
                'app_port' => 5678,
                'start_command' => 'node bin/n8n start',
            ],

            // ── Static profile — most sidebar sections should be hidden ──
            // Also `disabled_at` set: the only demo row showing the
            // Enable/disable "site paused" state (independent of `status`,
            // which stays `active` — a healthy site can still be paused).
            [
                'system_user_id' => $webUserId,
                'name' => 'Docs',
                'domain' => 'docs'.self::DOMAIN_SUFFIX,
                'site_type' => 'static',
                'serving_profile' => 'static',
                'rendering_type' => 'static',
                'status' => 'active',
                'web_root' => '/dist',
                'disabled_at' => now()->subDays(3),
            ],

            // ── Non-active states ────────────────────────────────────────
            [
                'system_user_id' => $webUserId,
                'name' => 'Marketing Site',
                'domain' => 'broken'.self::DOMAIN_SUFFIX,
                'site_type' => 'wordpress',
                'serving_profile' => 'php',
                'rendering_type' => 'php',
                'status' => 'failed',
                'php_version' => '8.4',
                'failed_step' => 'install_wordpress',
                'reference' => 'demo-0000-0000-0000-000000000001',
            ],
            [
                'system_user_id' => $appsUserId,
                'name' => 'Staging Sandbox',
                'domain' => 'sandbox'.self::DOMAIN_SUFFIX,
                'site_type' => 'php',
                'serving_profile' => 'php',
                'rendering_type' => 'php',
                'status' => 'pending',
                'php_version' => '8.4',
            ],
            [
                'system_user_id' => $appsUserId,
                'name' => 'Provisioning Demo',
                'domain' => 'provisioning'.self::DOMAIN_SUFFIX,
                'site_type' => 'php',
                'serving_profile' => 'php',
                'rendering_type' => 'php',
                'status' => 'provisioning',
                'php_version' => '8.4',
            ],
        ];
    }
}
