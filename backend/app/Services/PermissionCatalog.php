<?php

namespace App\Services;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Model;

/**
 * The permission catalog — the single definition of what can be granted.
 *
 * It lives in code rather than the database because it is a menu of features
 * this build ships, not data an operator authors. `sync()` is idempotent
 * (`updateOrCreate` on name+level, never a delete), which is what lets it run
 * on every deploy, from the admin sync button, and at first-admin
 * registration without anyone having to think about ordering.
 *
 * Unguarding happens here rather than at each call site: the artisan seed
 * command unguards automatically and the HTTP path used to have to remember
 * to, which is the kind of thing that works until the third caller.
 */
class PermissionCatalog
{
    public function __construct(private AdministratorRole $administratorRole) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return [
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

            // Integrations — externally-connected accounts/credentials the
            // features consume (git accounts for app deploys, storage
            // destinations for backups). Same `server` level, grouped under
            // their own sub-level so the sidebar renders them as a section.
            ['name' => 'git', 'title' => 'Git', 'icon' => 'git-branch', 'url' => '/integrations/git', 'order' => 13, 'sub_level' => 'integration'],
            ['name' => 'storage', 'title' => 'Storage', 'icon' => 'hard-drive', 'url' => '/integrations/storage', 'order' => 14, 'sub_level' => 'integration'],
        ];
    }

    /**
     * Write the catalog to the database and re-sync the Administrator role.
     *
     * Both halves, always, because they are one fact: a permission that
     * exists but is not on the protected role means the next admin silently
     * cannot use the feature it gates.
     */
    public function sync(): int
    {
        Model::unguarded(function (): void {
            foreach ($this->items() as $item) {
                Permission::updateOrCreate(
                    ['name' => $item['name'], 'level' => 'server'],
                    [
                        'sub_level' => $item['sub_level'] ?? 'server',
                        'title' => $item['title'],
                        'icon' => $item['icon'],
                        'url' => $item['url'],
                        'order' => $item['order'],
                    ]
                );
            }
        });

        $this->administratorRole->ensure();

        return count($this->items());
    }
}
