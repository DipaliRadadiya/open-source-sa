<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Models\Application;
use App\Services\Server\Php\MemoryBudget;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\WebServers\OlsVhostLayout;

/**
 * Whether each PHP site has the pool the panel thinks it has, and whether the pools together
 * fit in the machine.
 *
 * Both failures are silent: a site with a missing pool keeps serving from the shared pool as
 * www-data without its limits, and over-commitment surfaces as the OOM killer hitting another site.
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
            // OpenLiteSpeed spawns LSPHP itself, so there are no pools to check. The vhost names
            // an `lsphp` binary by path and OLS does not stat it when testing config, so a site
            // on a version with no lsphp is accepted, reported Active, and answers 503.
            return $this->interpreters();
        }

        // First: a pool naming a missing account fails `php-fpm -t` server-wide, so every new
        // PHP site fails to provision, and php-fpm will not start after the next restart.
        $orphans = $this->pools->unresolvableAccounts();

        if ($orphans !== []) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.php_orphans', ['pools' => implode(', ', array_map(
                    fn (array $orphan): string => __('doctor.details.php_orphan_item', ['pool' => basename($orphan['path']), 'user' => $orphan['user']]),
                    $orphans,
                ))]),
                'fix' => 'doctor.fixes.php_pool_orphaned',
            ];
        }

        $applications = Application::query()
            ->with('systemUser')
            ->where('serving_profile', 'php')
            ->get();

        $isolated = $applications->filter(fn (Application $a): bool => $a->isolated_at !== null);
        $shared = $applications->count() - $isolated->count();

        // An isolated site whose pool file is gone is still served, by the shared pool.
        $states = $isolated->map(fn (Application $a): array => ['app' => $a, 'exists' => $this->pools->exists($a)]);

        // Null is "could not look", not "gone": with an out-of-date sudo grant every probe
        // fails at once. That is PrivilegeCheck's finding; this check says only what it knows.
        $unknown = $states
            ->filter(fn (array $s): bool => $s['exists'] === null)
            ->map(fn (array $s): string => (string) $s['app']->domain)
            ->values();

        // `===`: Collection::where() compares loosely and `null == false`, which would fold
        // could-not-check into "missing".
        $missing = $states
            ->filter(fn (array $s): bool => $s['exists'] === false)
            ->map(fn (array $s): string => (string) $s['app']->domain)
            ->values();

        if ($missing->isNotEmpty()) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.php_pool_missing', ['sites' => $missing->implode(', ')]),
                'fix' => 'doctor.fixes.php_isolation_missing',
            ];
        }

        // After the real failure, so a missing pool is never buried under "could not check".
        if ($unknown->isNotEmpty()) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.php_pool_unknown', ['sites' => $unknown->implode(', ')]),
                'fix' => 'doctor.fixes.php_isolation_unknown',
            ];
        }

        $memory = $this->budget->forServer();

        if ($memory['over_committed']) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.php_memory', [
                    'count' => $memory['sites'],
                    'committed' => $this->human($memory['committed']),
                    'total' => $this->human($memory['total']),
                ]),
                'fix' => 'doctor.fixes.php_isolation_memory',
            ];
        }

        if ($shared > 0) {
            // Not a failure (isolation is opt-in per site), but without it per-site users mean little.
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.php_shared', ['count' => $shared]),
                'fix' => 'doctor.fixes.php_isolation_shared',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $applications->isEmpty()
                ? __('doctor.details.php_none')
                : __('doctor.details.php_ok', ['count' => $isolated->count()]),
            'fix' => null,
        ];
    }

    /**
     * Every PHP site's interpreter is on the box: the check for a stack with no pools.
     *
     * The version resolves as the vhost does, falling back to the configured default, so sites
     * that inherit an uninstalled default are caught too.
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
                'detail' => __('doctor.details.php_interpreter_missing', ['sites' => $missing
                    ->map(fn (array $site): string => __('doctor.details.php_interpreter_item', ['domain' => $site['domain'], 'version' => $site['version']]))
                    ->implode(', ')]),
                'fix' => 'doctor.fixes.php_interpreter_missing',
            ];
        }

        // Interpreter present is not isolation present: two separate silent failures.
        $shared = $this->vhostsRunningAsNobody();

        if ($shared !== []) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.php_ols_extuser', ['sites' => implode(', ', $shared)]),
                'fix' => 'doctor.fixes.ols_missing_extuser',
            ];
        }

        // OLS keeps one external app per name, so vhosts sharing a processor name all run as
        // whichever was loaded first.
        $collisions = $this->sharedProcessorNames();

        if ($collisions !== []) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.php_ols_collision', ['collisions' => collect($collisions)
                    ->map(fn (array $vhosts, string $name): string => __('doctor.details.php_ols_collision_item', ['name' => $name, 'vhosts' => implode(', ', $vhosts)]))
                    ->implode('; ')]),
                'fix' => 'doctor.fixes.ols_shared_processor',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => __('doctor.details.php_ok_no_pools', ['stack' => $stack->key()]),
            'fix' => null,
        ];
    }

    /**
     * OpenLiteSpeed vhosts with no `extUser`, which therefore run as nobody.
     *
     * The runtime half of the guard in `OlsDriver::assertHasSystemUser()`: finds vhosts already on
     * disk (older, adopted or hand-edited). Without `extUser` every such site shares one user,
     * so any of them can read the others' `.env`, database credentials and uploads.
     *
     * @return array<int, string>
     */
    private function vhostsRunningAsNobody(): array
    {
        $offenders = [];

        foreach ($this->lsapiVhosts() as $vhost => $contents) {
            if (preg_match('/^\s*extUser\s+\S+/m', $contents) !== 1) {
                $offenders[] = $vhost;
            }
        }

        return $offenders;
    }

    /**
     * LSAPI processor names defined by more than one vhost, with the vhosts.
     *
     * OpenLiteSpeed keeps one external app per name, so a shared name like `extprocessor lsphp84`
     * means the first vhost loaded supplies the extUser and socket for all. The panel now names
     * each processor after its site; this finds vhosts still written the old way or hand-edited.
     *
     * @return array<string, list<string>>
     */
    private function sharedProcessorNames(): array
    {
        $definedBy = [];

        foreach ($this->lsapiVhosts() as $vhost => $contents) {
            preg_match_all('/^\s*extprocessor\s+(\S+)\s*\{(.*?)^\s*\}/ms', $contents, $blocks, PREG_SET_ORDER);

            foreach ($blocks as [, $name, $body]) {
                if (preg_match('/^\s*type\s+lsapi\s*$/m', $body) === 1) {
                    $definedBy[$name][] = $vhost;
                }
            }
        }

        return array_filter($definedBy, fn (array $vhosts): bool => count(array_unique($vhosts)) > 1);
    }

    /**
     * Every OpenLiteSpeed vhost that runs PHP over LSAPI, keyed by its
     * directory name, with the file's contents.
     *
     * Read from disk, not the database: the file is what OpenLiteSpeed actually does.
     *
     * @return array<string, string>
     */
    private function lsapiVhosts(): array
    {
        // Where this server keeps them: a migrated box uses the old panel's directory.
        $root = rtrim(app(OlsVhostLayout::class)->root(), '/');

        if ($root === '' || ! is_dir($root)) {
            return [];
        }

        // Both names a vhost may have (a migrated server uses `main.conf`); matching only ours
        // would see no sites and report clean.
        $paths = [];

        foreach (OlsVhostLayout::FILENAMES as $filename) {
            $paths = array_merge($paths, (array) glob($root.'/*/'.$filename));
        }

        $vhosts = [];

        foreach ($paths as $path) {
            $contents = @file_get_contents((string) $path);

            // Unreadable is not missing: don't report a permissions problem as an isolation failure.
            if ($contents === false) {
                continue;
            }

            // Only vhosts that run PHP; static and proxy sites need no extUser.
            if (! str_contains($contents, 'type                    lsapi')
                && ! str_contains($contents, 'type lsapi')) {
                continue;
            }

            $vhosts[basename(dirname((string) $path))] = $contents;
        }

        return $vhosts;
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1).' GB'
            : round($bytes / 1024 ** 2).' MB';
    }
}
