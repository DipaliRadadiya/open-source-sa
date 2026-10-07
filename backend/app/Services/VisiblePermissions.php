<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Permission;
use App\Models\User;
use App\Services\Server\Capabilities\ServerCapabilities;

/**
 * The permissions a user can actually see, resolved in one place.
 *
 * Extracted because there were two implementations: this one, and a copy in
 * the application sidebar that called `$user->permissions()` — a relation
 * removed when direct per-user grants were dropped in favour of roles. The
 * copy had been a 500 on every sidebar request ever since, which is what
 * having two answers to one question buys.
 */
class VisiblePermissions
{
    /**
     * Screens with nothing for a view-only role to do.
     *
     * `app_environment`: operator decision 2026-09-29 — the `.env` screen is
     * shown only to who may edit it (a viewer already got no file contents
     * since 77661c7b, only variable names). `app_magic_login`: its routes were
     * manage-only from the start, so a viewer was shown a menu entry that
     * could only answer 403. The routes enforce the same rule; this is what
     * the sidebar and `/permissions` say about it.
     */
    public const MANAGE_ONLY = ['app_environment', 'app_magic_login'];

    public function for(User $user, ?string $level, ?Application $application = null): array
    {
        $query = Permission::query()->orderBy('order');

        if ($level) {
            $query->where('level', $level);
        }

        $permissions = $query->get();

        // The second filter. Granting `app_deployment` gives it on every site,
        // but a WordPress install has no repository — the screen would be about
        // nothing. Applied here rather than in the frontend so a new site type
        // costs one class and no frontend change, and so there is one answer
        // rather than fourteen special cases spread across screens.
        //
        // Hide rather than grey: there is nothing the user could do to enable
        // PHP settings on a static site, so a disabled row is only noise.
        // Greying is for things they can fix.
        if ($application !== null) {
            $features = $application->features();

            $permissions = $permissions->filter(
                fn (Permission $permission) => $permission->level !== 'application'
                    || in_array($permission->name, $features, true)
            );
        }

        // The third filter, and the server-level counterpart of the one above: a
        // feature the STACK cannot do is a screen about nothing.
        //
        // A map rather than a chain of ifs, because each entry is one fact and the
        // next stack should add a line rather than a branch. Each answer comes from
        // `ServerCapabilities`, which is where the rule lives for the middleware
        // that refuses the same endpoints — a screen hidden here whose routes still
        // answer is the worse state, so the two must agree by construction rather
        // than by both being remembered.
        //
        // `php` asks `hosts('php')` and never `can('php')`: PHP is installed on a
        // Docker box because the panel is a Laravel application, so the latter
        // would hide it nowhere. A server with no recorded stack answers true to
        // both by design — refusing a working migrated box its screens because
        // nobody wrote a capability row is far worse than an extra tab.
        //
        // Docker was deliberately absent here until somebody asked for that tab to
        // go. They have. It was the one entry whose omission was visible rather
        // than merely untidy: `EnsureServerHostsContainers` already answers 409 on
        // all seventeen Docker routes off a container stack, so the menu offered a
        // page whose every request failed — a button whose only outcome is an
        // error, which is worse than no button.
        if ($level === 'server' || $level === null) {
            $capabilities = app(ServerCapabilities::class);

            $unavailable = array_keys(array_filter([
                'php' => ! $capabilities->hosts('php'),
                // Both of these ask whether anything runs on the HOST, not
                // whether the matching runtime is hosted. For databases that is
                // the rule the middleware already used; for Node it is the
                // deliberate difference — see `runsHostApplications()` for why
                // `hosts('node')` would take the screen away from every LEMP box
                // whose sites build their assets with npm.
                'node' => ! $capabilities->runsHostApplications(),
                'database' => ! $capabilities->managesDatabases(),
                // Networks, volumes and containerised databases — all of it is
                // about containers, and a box that serves none has none to show.
                // Same capability the middleware refuses on, so the tab and the
                // routes cannot disagree.
                'docker' => ! $capabilities->hosts('docker'),
                // The mirror of the three above: a credential for pulling container
                // images is nothing on a box that runs no containers. Its endpoints
                // are gated on the same capability, so the tab and the routes agree.
                'registry' => ! $capabilities->hosts('docker'),
            ]));

            if ($unavailable !== []) {
                $permissions = $permissions->reject(
                    fn (Permission $permission) => $permission->level === 'server'
                        && in_array($permission->name, $unavailable, true)
                );
            }
        }

        // Build effective {view, manage} per permission id, merged across
        // direct grants + every role the user holds (dedup by OR).
        $effective = [];
        $merge = function ($grants) use (&$effective) {
            foreach ($grants as $grant) {
                $current = $effective[$grant->id] ?? ['view' => false, 'manage' => false];
                $effective[$grant->id] = [
                    'view' => $current['view'] || (bool) $grant->pivot->view || (bool) $grant->pivot->manage,
                    'manage' => $current['manage'] || (bool) $grant->pivot->manage,
                ];
            }
        };

        $user->roles()->with('permissions')->get()->each(fn ($role) => $merge($role->permissions));

        return $permissions
            ->map(function (Permission $permission) use ($effective) {
                $grant = $effective[$permission->id] ?? ['view' => false, 'manage' => false];

                // A screen that is only for changing things is only shown to
                // someone who may change them: a view grant alone counts for
                // nothing, so it leaves both the sidebar and the frontend's
                // own `can(view)` guard. See MANAGE_ONLY.
                if (in_array($permission->name, self::MANAGE_ONLY, true)) {
                    $grant['view'] = $grant['manage'];
                }

                return [
                    'level' => $permission->level,
                    'sub_level' => $permission->sub_level,
                    'sub_level_title' => $permission->localizedSubLevel(),
                    'name' => $permission->name,
                    'title' => $permission->localizedTitle(),
                    'icon' => $permission->icon,
                    'url' => $permission->url,
                    'permissions' => ['view' => $grant['view'], 'manage' => $grant['manage']],
                ];
            })
            ->filter(fn (array $item) => $item['permissions']['view'] || $item['permissions']['manage'])
            ->values()
            ->all();
    }
}
