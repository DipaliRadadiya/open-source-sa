<?php

namespace App\Services;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Model;

/**
 * The permission catalog — the single definition of what can be granted.
 *
 * It lives in code, not the database: it is the menu of features this build ships.
 * `sync()` is idempotent (`updateOrCreate` on name+level, never a delete), so it can run on
 * every deploy, from the admin sync button, and at first-admin registration. Unguarding
 * happens here, not at each call site.
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
            ['name' => 'application', 'title' => 'Applications', 'icon' => 'app-window', 'url' => '/applications', 'order' => 2],
            ['name' => 'database', 'title' => 'Databases', 'icon' => 'database', 'url' => '/databases', 'order' => 3],
            ['name' => 'system_user', 'title' => 'System Users', 'icon' => 'users', 'url' => '/system-users', 'order' => 4],
            ['name' => 'firewall', 'title' => 'Firewall', 'icon' => 'shield', 'url' => '/firewall', 'order' => 5],
            ['name' => 'cronjob', 'title' => 'Cron Jobs', 'icon' => 'clock', 'url' => '/cron-jobs', 'order' => 6],
            ['name' => 'fail2ban', 'title' => 'Fail2ban', 'icon' => 'ban', 'url' => '/fail2ban', 'order' => 7],
            ['name' => 'logs', 'title' => 'System Logs', 'icon' => 'file-text', 'url' => '/logs', 'order' => 8],
            ['name' => 'service', 'title' => 'Services', 'icon' => 'settings-2', 'url' => '/services', 'order' => 9],
            // PHP has its own permission: sharing `setting` would let "change the PHP version"
            // also mean "reboot the server and move the SSH port".
            ['name' => 'php', 'title' => 'PHP', 'icon' => 'file-code', 'url' => '/php', 'order' => 10],
            ['name' => 'node', 'title' => 'Node.js', 'icon' => 'hexagon', 'url' => '/node', 'order' => 11],
            // Networks and volumes share one permission (the shared Docker objects containers
            // use). Registry credentials are NOT here; they are an integration below.
            ['name' => 'docker', 'title' => 'Docker', 'icon' => 'container', 'url' => '/docker', 'order' => 11.5],
            ['name' => 'setting', 'title' => 'Settings', 'icon' => 'settings', 'url' => '/settings', 'order' => 12],
            ['name' => 'disk_cleaner', 'title' => 'Disk Cleaner', 'icon' => 'trash-2', 'url' => '/disk-cleaner', 'order' => 13],
            // Backups dashboard: history and restore across every app and database. Scheduling
            // is per application (`app_backup`); restore overwrites live data, so one screen.
            ['name' => 'backup', 'title' => 'Backups', 'icon' => 'archive', 'url' => '/backups', 'order' => 14],
            ['name' => 'activity_log', 'title' => 'Activity Log', 'icon' => 'history', 'url' => '/activity-log', 'order' => 15],
            // Own permission, not `setting`: a sync writes users, sites and databases at once.
            ['name' => 'sync', 'title' => 'Server Sync', 'icon' => 'refresh-cw', 'url' => '/sync', 'order' => 16],

            // Integrations — external accounts/credentials the features consume. Same `server`
            // level; the sub-level makes the sidebar render them as a section.
            ['name' => 'git', 'title' => 'Git', 'icon' => 'git-branch', 'url' => '/integrations/git', 'order' => 16, 'sub_level' => 'integration'],
            ['name' => 'storage', 'title' => 'Storage', 'icon' => 'hard-drive', 'url' => '/integrations/storage', 'order' => 17, 'sub_level' => 'integration'],
            // Registry credentials: an external credential like a git account, so an integration.
            // Its own permission, not `docker`: `docker` manages networks and volumes, this
            // stores a credential that can pull private code onto the box.
            ['name' => 'registry', 'title' => 'Docker Registries', 'icon' => 'key-round', 'url' => '/integrations/registries', 'order' => 18, 'sub_level' => 'integration'],

            // ── Application level ────────────────────────────────────────
            //
            // The sidebar *inside* an application; every item needs its own row because the
            // permission row IS the nav entry.
            //
            // ⚠️ The `app_` prefix is load-bearing: `User::hasAbility()` matches on name and
            // ignores `level`, so a row named `logs` here would grant the server-level `logs`
            // through the application one. Keep names globally unique.
            //
            // Never gate an app screen with a server permission: server `logs` is the whole
            // box's auth.log/syslog, `app_log` is one site's access log.
            //
            // `url` is a segment, not a path: the route is /applications/{id}{url}.
            ...$this->applicationItems(),
        ];
    }

    /**
     * The application sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function applicationItems(): array
    {
        $items = [
            ['name' => 'app_dashboard', 'title' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => ''],
            // SSL lives with domains: a certificate covers a set of names, so it is one decision.
            ['name' => 'app_domain', 'title' => 'Domains & SSL', 'icon' => 'globe', 'url' => '/domains'],
            ['name' => 'app_deployment', 'title' => 'Deployments', 'icon' => 'git-branch', 'url' => '/deployment'],
            ['name' => 'app_environment', 'title' => 'Environment', 'icon' => 'file-key', 'url' => '/environment'],
            // Container settings (network, port, memory, volumes, registry, generated
            // credentials). Structured settings here, the raw compose file next door.
            ['name' => 'app_container', 'title' => 'Container', 'icon' => 'box', 'url' => '/container'],
            // Container sites only: the site-type filter in `VisiblePermissions` drops it
            // elsewhere. A container has no Environment screen; its variables live in this file.
            ['name' => 'app_compose', 'title' => 'Compose File', 'icon' => 'file-code-2', 'url' => '/compose'],
            // Supervisor workers and a Node process are one screen: "what runs in the background?".
            ['name' => 'app_worker', 'title' => 'Workers', 'icon' => 'cpu', 'url' => '/workers'],
            ['name' => 'app_file', 'title' => 'Files', 'icon' => 'folder', 'url' => '/files'],
            ['name' => 'app_log', 'title' => 'Logs', 'icon' => 'file-text', 'url' => '/logs'],
            ['name' => 'app_backup', 'title' => 'Backups', 'icon' => 'archive', 'url' => '/backups'],
            // No `app_setting`: enable/disable is on the Dashboard, gated by `application`.
            ['name' => 'app_php', 'title' => 'PHP Settings', 'icon' => 'file-code', 'url' => '/php'],
            ['name' => 'app_security', 'title' => 'Password Protection', 'icon' => 'lock', 'url' => '/security'],
            // "Web Firewall", not "Firewall": this is the 8G request rule set, while the
            // server-level `firewall` is ufw (ports). Title only: renaming the `app_firewall`
            // NAME would revoke this screen from every existing role.
            ['name' => 'app_firewall', 'title' => 'Web Firewall', 'icon' => 'shield', 'url' => '/firewall'],
            ['name' => 'app_bot_blocker', 'title' => 'AI Bot Blocker', 'icon' => 'bot', 'url' => '/bot-blocker'],
            ['name' => 'app_fail2ban', 'title' => 'Fail2ban', 'icon' => 'ban', 'url' => '/fail2ban'],
            ['name' => 'app_staging', 'title' => 'Staging Area', 'icon' => 'flask-conical', 'url' => '/staging'],
            ['name' => 'app_clone', 'title' => 'Application Clone', 'icon' => 'copy', 'url' => '/clone'],
            // Not a nav entry: `url` is null because Magic Login is a Dashboard button. The
            // sidebar skips null-url rows; the role editor still lists it.
            //
            // Own permission because it grants a full WordPress administrator session; folding
            // it into `app_dashboard` would upgrade every read-only role. Held by
            // WordPressSiteType alone, so other site types 404 the routes.
            ['name' => 'app_magic_login', 'title' => 'Magic Login', 'icon' => 'log-in', 'url' => null],
        ];

        return array_map(
            fn (array $item, int $index) => $item + ['level' => 'application', 'order' => $index + 1],
            $items,
            array_keys($items),
        );
    }

    /**
     * Write the catalog to the database and re-sync the Administrator role.
     *
     * Both halves, always: a permission missing from the protected role leaves admins
     * unable to use the feature it gates.
     */
    public function sync(): int
    {
        Model::unguarded(function (): void {
            foreach ($this->items() as $item) {
                Permission::updateOrCreate(
                    // Name AND level identify a row; names stay unique across levels anyway.
                    ['name' => $item['name'], 'level' => $item['level'] ?? 'server'],
                    [
                        'sub_level' => $item['sub_level'] ?? ($item['level'] ?? 'server'),
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
