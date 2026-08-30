<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Models\Application;
use App\Services\Server\Php\MemoryBudget;
use App\Services\Server\Php\PoolManager;

/**
 * Whether each PHP site actually has the pool the panel thinks it has, and
 * whether the pools together fit in the machine.
 *
 * Both failures are silent by nature. A site whose pool file went missing
 * keeps serving — from the shared pool, as www-data, with none of the limits
 * anyone set — and nothing anywhere says so. Over-commitment shows up as the
 * OOM killer taking out a *different* site at three in the morning.
 */
class PhpIsolationCheck implements DoctorCheck
{
    public function __construct(
        private PoolManager $pools,
        private MemoryBudget $budget,
    ) {}

    public function key(): string
    {
        return 'php_isolation';
    }

    /**
     * @return array{status: 'pass'|'warn'|'fail', detail: string|null, fix: string|null}
     */
    public function run(): array
    {
        if (! $this->pools->supported()) {
            // OpenLiteSpeed spawns LSPHP itself; there are no pools to check.
            return ['status' => 'pass', 'detail' => 'OpenLiteSpeed — no FPM pools', 'fix' => null];
        }

        // First, because it is the only one here that is already breaking
        // things elsewhere. A pool naming an account that no longer exists
        // fails `php-fpm -t` server-wide, so every new PHP site fails to
        // provision and is blamed for it — and php-fpm will not start at all
        // after the next restart.
        $orphans = $this->pools->unresolvableAccounts();

        if ($orphans !== []) {
            return [
                'status' => 'fail',
                'detail' => 'pool(s) naming a missing account: '.implode(', ', array_map(
                    fn (array $orphan): string => basename($orphan['path']).' (user '.$orphan['user'].')',
                    $orphans,
                )),
                'fix' => 'doctor.fixes.php_pool_orphaned',
            ];
        }

        $applications = Application::query()
            ->with('systemUser')
            ->where('serving_profile', 'php')
            ->get();

        $isolated = $applications->filter(fn (Application $a): bool => $a->isolated_at !== null);
        $shared = $applications->count() - $isolated->count();

        // A site the panel believes is isolated but whose pool file is gone.
        // It is still being served — by the shared pool, as www-data, with
        // none of its settings — and nothing else would ever report it.
        $states = $isolated->map(fn (Application $a): array => ['app' => $a, 'exists' => $this->pools->exists($a)]);

        // Null is "could not look", and it must not be reported as "gone".
        // Every probe fails at once on a server whose sudo grant is out of
        // date, so reading null as false announced a missing pool for every
        // isolated site on the box — a false alarm at its loudest, in the tool
        // someone opens when something is already wrong. The missing grant is
        // PrivilegeCheck's finding and it fails there; this one says only what
        // it knows.
        $unknown = $states
            ->filter(fn (array $s): bool => $s['exists'] === null)
            ->map(fn (array $s): string => (string) $s['app']->domain)
            ->values();

        // `===`, because Collection::where() compares loosely and `null == false`
        // is true — which would have folded every could-not-check straight back
        // into "missing", the exact bug this is fixing.
        $missing = $states
            ->filter(fn (array $s): bool => $s['exists'] === false)
            ->map(fn (array $s): string => (string) $s['app']->domain)
            ->values();

        if ($missing->isNotEmpty()) {
            return [
                'status' => 'fail',
                'detail' => 'pool file missing for '.$missing->implode(', '),
                'fix' => 'doctor.fixes.php_isolation_missing',
            ];
        }

        // After the real failure, so a genuinely missing pool is never buried
        // under "could not check the others".
        if ($unknown->isNotEmpty()) {
            return [
                'status' => 'warn',
                'detail' => 'could not check the pool file for '.$unknown->implode(', '),
                'fix' => 'doctor.fixes.php_isolation_unknown',
            ];
        }

        $memory = $this->budget->forServer();

        if ($memory['over_committed']) {
            return [
                'status' => 'warn',
                'detail' => sprintf(
                    '%d isolated site(s) may use up to %s of %s',
                    $memory['sites'],
                    $this->human($memory['committed']),
                    $this->human($memory['total']),
                ),
                'fix' => 'doctor.fixes.php_isolation_memory',
            ];
        }

        if ($shared > 0) {
            // Not a failure — every site worked this way until pools existed,
            // and isolating is deliberately one site at a time. But it is the
            // difference between per-site users meaning something and not.
            return [
                'status' => 'warn',
                'detail' => "{$shared} PHP site(s) still share the server pool and run as www-data",
                'fix' => 'doctor.fixes.php_isolation_shared',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $applications->isEmpty()
                ? 'no PHP sites'
                : $isolated->count().' PHP site(s), each in its own pool',
            'fix' => null,
        ];
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1).' GB'
            : round($bytes / 1024 ** 2).' MB';
    }
}
