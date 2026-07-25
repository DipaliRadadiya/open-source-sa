<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Services\AdministratorRole;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'dashboard', 'title' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => '/dashboard', 'order' => 1],
            ['name' => 'application', 'title' => 'Application', 'icon' => 'app-window', 'url' => '/applications', 'order' => 2],
            ['name' => 'database', 'title' => 'Database', 'icon' => 'database', 'url' => '/databases', 'order' => 3],
            ['name' => 'system_user', 'title' => 'System User', 'icon' => 'users', 'url' => '/system-users', 'order' => 4],
            ['name' => 'firewall', 'title' => 'Firewall', 'icon' => 'shield', 'url' => '/firewall', 'order' => 5],
            ['name' => 'cronjob', 'title' => 'Cronjob', 'icon' => 'clock', 'url' => '/cron-jobs', 'order' => 6],
            ['name' => 'fail2ban', 'title' => 'Fail2ban', 'icon' => 'ban', 'url' => '/fail2ban', 'order' => 7],
            ['name' => 'logs', 'title' => 'Logs', 'icon' => 'file-text', 'url' => '/logs', 'order' => 8],
            ['name' => 'service', 'title' => 'Service', 'icon' => 'settings-2', 'url' => '/services', 'order' => 9],
            ['name' => 'setting', 'title' => 'Setting', 'icon' => 'settings', 'url' => '/settings', 'order' => 10],
            ['name' => 'disk_cleaner', 'title' => 'Disk Cleaner', 'icon' => 'trash-2', 'url' => '/disk-cleaner', 'order' => 11],
            ['name' => 'activity_log', 'title' => 'Activity Log', 'icon' => 'history', 'url' => '/activity-log', 'order' => 12],
        ];

        foreach ($items as $item) {
            Permission::updateOrCreate(
                ['name' => $item['name'], 'level' => 'server'],
                [
                    'sub_level' => 'server',
                    'title' => $item['title'],
                    'icon' => $item['icon'],
                    'url' => $item['url'],
                    'order' => $item['order'],
                ]
            );
        }

        // Create/refresh the protected Administrator role holding ALL
        // permissions. Idempotent — re-running (every deploy) re-syncs so
        // new permissions are automatically added to it.
        app(AdministratorRole::class)->ensure();
    }
}
