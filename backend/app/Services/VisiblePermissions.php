<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Permission;
use App\Models\User;
use App\Services\Server\Capabilities\ServerCapabilities;

/**
 * The permissions a user can actually see, resolved in one place (sidebar and `/permissions`).
 */
class VisiblePermissions
{
    /**
     * Screens with nothing for a view-only role to do: `app_environment` (the `.env` screen) and
     * `app_magic_login` (its routes are manage-only, so a viewer would only get a 403).
     */
    public const MANAGE_ONLY = ['app_environment', 'app_magic_login'];

    public function for(User $user, ?string $level, ?Application $application = null): array
    {
        $query = Permission::query()->orderBy('order');

        if ($level) {
            $query->where('level', $level);
        }

        $permissions = $query->get();

        // Second filter: hide screens the site type does not have (e.g. deployment on WordPress).
        // Done here, not in the frontend, so a new site type needs no frontend change. Hidden
        // rather than greyed: the user could not enable it anyway.
        if ($application !== null) {
            $features = $application->features();

            $permissions = $permissions->filter(
                fn (Permission $permission) => $permission->level !== 'application'
                    || in_array($permission->name, $features, true)
            );
        }

        // Third filter: hide server screens the STACK cannot do. The answers come from
        // `ServerCapabilities`, the same rule the middleware uses to refuse those endpoints, so the
        // tab and the routes cannot disagree.
        //
        // `php` asks `hosts('php')`, never `can('php')`: PHP is installed on a Docker box too
        // (the panel is Laravel). A server with no recorded stack answers true to both by design.
        if ($level === 'server' || $level === null) {
            $capabilities = app(ServerCapabilities::class);

            $unavailable = array_keys(array_filter([
                'php' => ! $capabilities->hosts('php'),
                // These two ask whether anything runs on the HOST, not whether the runtime is hosted;
                // see `runsHostApplications()` for why `hosts('node')` would hide Node on LEMP boxes.
                'node' => ! $capabilities->runsHostApplications(),
                'database' => ! $capabilities->managesDatabases(),
                // Networks, volumes and containerised databases: all about containers.
                'docker' => ! $capabilities->hosts('docker'),
                // Registry credentials are for pulling container images.
                'registry' => ! $capabilities->hosts('docker'),
            ]));

            if ($unavailable !== []) {
                $permissions = $permissions->reject(
                    fn (Permission $permission) => $permission->level === 'server'
                        && in_array($permission->name, $unavailable, true)
                );
            }
        }

        // Effective {view, manage} per permission id, merged across every role the user holds (OR).
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

                // Manage-only screens: a view grant alone counts for nothing. See MANAGE_ONLY.
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
