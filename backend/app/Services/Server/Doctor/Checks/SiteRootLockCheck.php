<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Models\Application;
use App\Services\Server\Applications\SiteRootLock;

/**
 * Every site root carries the immutable flag — see SiteRootLock.
 *
 * Without it the site's user can rename the root-owned site directory out of
 * their home and put their own in its place, which defeats the locked PHP
 * config and turns the panel's own writes into writes wherever the substitute
 * points. `sites:resync` applies the flag on every deploy, so a site found
 * without it is one resync could not lock: a filesystem without the attribute,
 * or a site directory that did not look like the one the panel created.
 *
 * Read-only: this reports, it never locks. Locking a directory that is not the
 * real one is the thing SiteRootLock takes care to refuse, and a health check
 * is the wrong place to make that decision.
 */
class SiteRootLockCheck implements DoctorCheck
{
    public function __construct(private SiteRootLock $rootLock) {}

    public function key(): string
    {
        return 'site_root_lock';
    }

    public function run(): array
    {
        $unlocked = [];
        $unknown = [];
        $total = 0;

        foreach (Application::query()->with('systemUser')->get() as $application) {
            if ($application->systemUser === null) {
                continue;
            }

            $total++;

            match ($this->rootLock->isLocked($application)) {
                true => null,
                false => $unlocked[] = $application->name,
                // Null is "could not look" — a filesystem with no attribute to
                // read, or no answer at all — not "unlocked".
                null => $unknown[] = $application->name,
            };
        }

        if ($unlocked === [] && $unknown === []) {
            return [
                'status' => 'pass',
                'detail' => $total === 0 ? 'no sites' : $total.' site root(s) locked',
                'fix' => null,
            ];
        }

        $parts = array_filter([
            $unlocked !== [] ? 'not locked: '.implode(', ', $unlocked) : null,
            $unknown !== [] ? 'could not be checked: '.implode(', ', $unknown) : null,
        ]);

        return [
            'status' => 'warn',
            'detail' => implode('; ', $parts),
            'fix' => 'doctor.fixes.site_root_unlocked',
        ];
    }
}
