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
            // PHP is its own feature, not a corner of Settings. Sharing the
            // `setting` permission meant "can change the PHP version" also
            // meant "can reboot the server and move the SSH port".
            ['name' => 'php', 'title' => 'PHP', 'icon' => 'file-code', 'url' => '/php', 'order' => 10],
            ['name' => 'node', 'title' => 'Node.js', 'icon' => 'hexagon', 'url' => '/node', 'order' => 11],
            ['name' => 'setting', 'title' => 'Setting', 'icon' => 'settings', 'url' => '/settings', 'order' => 12],
            ['name' => 'disk_cleaner', 'title' => 'Disk Cleaner', 'icon' => 'trash-2', 'url' => '/disk-cleaner', 'order' => 13],
            // The backups dashboard: history and restore across every app and
            // database. Scheduling a backup happens inside an application
            // (`app_backup`), but the list you restore from is one place —
            // restore overwrites live data, and one screen means one set of
            // guardrails rather than two.
            ['name' => 'backup', 'title' => 'Backup', 'icon' => 'archive', 'url' => '/backups', 'order' => 14],
            ['name' => 'activity_log', 'title' => 'Activity Log', 'icon' => 'history', 'url' => '/activity-log', 'order' => 15],

            // Integrations — externally-connected accounts/credentials the
            // features consume (git accounts for app deploys, storage
            // destinations for backups). Same `server` level, grouped under
            // their own sub-level so the sidebar renders them as a section.
            ['name' => 'git', 'title' => 'Git', 'icon' => 'git-branch', 'url' => '/integrations/git', 'order' => 16, 'sub_level' => 'integration'],
            ['name' => 'storage', 'title' => 'Storage', 'icon' => 'hard-drive', 'url' => '/integrations/storage', 'order' => 17, 'sub_level' => 'integration'],

            // ── Application level ────────────────────────────────────────
            //
            // The sidebar *inside* an application. `GET /permissions?level=
            // application` renders it exactly as `?level=server` renders the
            // one above — so every item needs its own row here, because the
            // permission row IS the nav entry.
            //
            // ⚠️ The `app_` prefix is load-bearing, not decoration.
            // `User::hasAbility()` matches on name and ignores `level`, so a
            // row named `logs` here would collide with the server-level
            // `logs` and quietly grant one through the other. Keeping names
            // globally unique is what lets hasAbility and the `permission:`
            // middleware stay untouched. Do not "tidy" this away.
            //
            // And never gate an app screen with a server permission: server
            // `logs` is auth.log and syslog for the whole box, `app_log` is
            // one site's access log. Same word, different blast radius.
            //
            // `url` is a segment, not a path — the real route is
            // /applications/{id}{url} — because these only exist within an
            // application.
            ...$this->applicationItems(),
        ];
    }

    /**
     * The application sidebar.
     *
     * Seeded in full rather than feature-by-feature: an operator sets up roles
     * once, and the screens light up as they ship without anyone having to
     * revisit the role editor.
     *
     * @return array<int, array<string, mixed>>
     */
    private function applicationItems(): array
    {
        $items = [
            ['name' => 'app_dashboard', 'title' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => ''],
            // SSL lives with domains, not beside it: a certificate covers a
            // *set* of names, so the two are one decision.
            ['name' => 'app_domain', 'title' => 'Domains & SSL', 'icon' => 'globe', 'url' => '/domains'],
            ['name' => 'app_deployment', 'title' => 'Deployment', 'icon' => 'git-branch', 'url' => '/deployment'],
            ['name' => 'app_environment', 'title' => 'Environment', 'icon' => 'file-key', 'url' => '/environment'],
            // Supervisor workers and a Node process are the same question —
            // "what is running in the background?" — so they are one screen
            // with different tooling underneath, not two menu items.
            ['name' => 'app_worker', 'title' => 'Workers', 'icon' => 'cpu', 'url' => '/workers'],
            ['name' => 'app_file', 'title' => 'Files', 'icon' => 'folder', 'url' => '/files'],
            ['name' => 'app_log', 'title' => 'Logs', 'icon' => 'file-text', 'url' => '/logs'],
            ['name' => 'app_backup', 'title' => 'Backups', 'icon' => 'archive', 'url' => '/backups'],
            // No `app_setting` — there is no dedicated Settings screen.
            // Enable/disable lives directly on the Dashboard, gated by the
            // same `application` permission every other write on this
            // resource already uses, not a per-app permission of its own.
            ['name' => 'app_php', 'title' => 'PHP Settings', 'icon' => 'file-code', 'url' => '/php'],
            ['name' => 'app_security', 'title' => 'Password Protection', 'icon' => 'lock', 'url' => '/security'],
            ['name' => 'app_firewall', 'title' => 'Firewall', 'icon' => 'shield', 'url' => '/firewall'],
            ['name' => 'app_bot_blocker', 'title' => 'AI Bot Blocker', 'icon' => 'bot', 'url' => '/bot-blocker'],
            ['name' => 'app_fail2ban', 'title' => 'Fail2ban', 'icon' => 'ban', 'url' => '/fail2ban'],
            ['name' => 'app_staging', 'title' => 'Staging Area', 'icon' => 'flask-conical', 'url' => '/staging'],
            ['name' => 'app_clone', 'title' => 'Site Clone', 'icon' => 'copy', 'url' => '/clone'],
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
     * Both halves, always, because they are one fact: a permission that
     * exists but is not on the protected role means the next admin silently
     * cannot use the feature it gates.
     */
    public function sync(): int
    {
        Model::unguarded(function (): void {
            foreach ($this->items() as $item) {
                Permission::updateOrCreate(
                    // Name AND level identify a row, but names are kept unique
                    // across levels anyway — see the note above the
                    // application items for why that matters.
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
