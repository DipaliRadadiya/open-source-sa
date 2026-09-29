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

        // The third filter, and the server-level counterpart of the one above:
        // a feature the STACK cannot do is a screen about nothing.
        //
        // Only `php` for now, deliberately. The same reasoning applies to
        // Databases and Node.js on a Docker box, and to Docker on a LEMP box —
        // Databases in particular already answers 409 there, so that tab is
        // live-broken today. Those are a product decision about how much of the
        // sidebar vanishes, not a mechanical consequence of this one, so they are
        // left to be asked for rather than assumed.
        //
        // `hosts('php')`, never `can('php')`: PHP is installed on a Docker box
        // because the panel is a Laravel application, and gating on that would
        // hide this nowhere. A server with no recorded stack answers `true` by
        // design — refusing a working migrated LEMP box its PHP screen because
        // nobody wrote a capability row is a far worse failure than one extra tab.
        if ($level === 'server' || $level === null) {
            $hostsPhp = app(ServerCapabilities::class)->hosts('php');

            if (! $hostsPhp) {
                $permissions = $permissions->reject(
                    fn (Permission $permission) => $permission->level === 'server' && $permission->name === 'php'
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
