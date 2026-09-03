<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Models\Application;
use App\Services\Server\Php\MemoryBudget;
use App\Services\Server\Php\PhpStackManager;
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
        private PhpStackManager $stacks,
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
            // OpenLiteSpeed spawns LSPHP itself, so there are no pools — but
            // this used to stop here and report `pass`, which made it a check
            // that answered "fine" on the one stack it had never looked at.
            //
            // There is something to check, and it is the OLS equivalent of the
            // orphaned pool above: the vhost names an `lsphp` binary by path,
            // and OpenLiteSpeed does not stat it when the config is tested. A
            // site on a version with no lsphp installed is therefore accepted,
            // reported Active, and answers 503 on every request with nothing
            // anywhere saying why. Creating one is now refused up front, but a
            // site created before that, adopted from a brownfield server, or
            // left behind when a version was removed still needs naming.
            return $this->interpreters();
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

    /**
     * Every PHP site's interpreter is on the box — the check for a stack that
     * has no pools.
     *
     * The version is resolved the same way the vhost resolves it, falling back
     * to the configured default for a site that names none: checking only the
     * sites with an explicit version would miss exactly the ones that inherit
     * a default nobody has installed.
     *
     * @return array{status: 'pass'|'warn'|'fail', detail: string|null, fix: string|null}
     */
    private function interpreters(): array
    {
        $stack = $this->stacks->stack();
        $default = (string) config('server.default_php_version', '');

        $missing = Application::query()
            ->where('serving_profile', 'php')
            ->get()
            ->map(fn (Application $a): array => [
                'domain' => (string) $a->domain,
                'version' => (string) ($a->php_version ?: $default),
            ])
            ->filter(fn (array $site): bool => $site['version'] !== '' && ! $stack->installed($site['version']))
            ->values();

        if ($missing->isNotEmpty()) {
            return [
                'status' => 'fail',
                'detail' => 'PHP not installed for '.$missing
                    ->map(fn (array $site): string => "{$site['domain']} (needs {$site['version']})")
                    ->implode(', '),
                'fix' => 'doctor.fixes.php_interpreter_missing',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $stack->key().' — no FPM pools; every PHP site has its interpreter',
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
