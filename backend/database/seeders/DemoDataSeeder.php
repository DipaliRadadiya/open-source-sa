<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\SystemUser;
use Illuminate\Database\Seeder;
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
 * Every row is written with firstOrCreate keyed on a unique column, so the
 * seeder is re-runnable and never edits or deletes anything that already
 * exists — it shares a database with whoever is developing against it.
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
            Application::firstOrCreate(
                ['domain' => $attributes['domain']],
                $attributes,
            );
        }

        $this->command?->info('Demo data seeded. Remove it with: Application::where("domain", "like", "%'.self::DOMAIN_SUFFIX.'")->delete()');
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
            [
                'system_user_id' => $webUserId,
                'name' => 'Docs',
                'domain' => 'docs'.self::DOMAIN_SUFFIX,
                'site_type' => 'static',
                'serving_profile' => 'static',
                'rendering_type' => 'static',
                'status' => 'active',
                'web_root' => '/dist',
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
