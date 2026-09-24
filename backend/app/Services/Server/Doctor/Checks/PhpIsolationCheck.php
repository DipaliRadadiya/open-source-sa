<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Models\Application;
use App\Services\Server\Php\MemoryBudget;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\WebServers\OlsVhostLayout;

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

        // Interpreter present is not the same as isolation present. On this
        // stack they are two separate silent failures, and the second is the
        // dangerous one.
        $shared = $this->vhostsRunningAsNobody();

        if ($shared !== []) {
            return [
                'status' => 'fail',
                'detail' => 'no extUser in the OpenLiteSpeed vhost for '.implode(', ', $shared)
                    .' — those sites run as nobody, so each can read the others\' files',
                'fix' => 'doctor.fixes.ols_missing_extuser',
            ];
        }

        // Every vhost can name its user and still not run as it: OpenLiteSpeed
        // keeps one external app per name, so vhosts sharing a processor name
        // all run as whichever of them it loaded first.
        $collisions = $this->sharedProcessorNames();

        if ($collisions !== []) {
            return [
                'status' => 'fail',
                'detail' => collect($collisions)
                    ->map(fn (array $vhosts, string $name): string => "{$name} is defined by ".implode(', ', $vhosts))
                    ->implode('; ').' — OpenLiteSpeed runs one process per name, so those sites share one user',
                'fix' => 'doctor.fixes.ols_shared_processor',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $stack->key().' — no FPM pools; every PHP site has its interpreter and its own user',
            'fix' => null,
        ];
    }

    /**
     * OpenLiteSpeed vhosts with no `extUser`, which therefore run as nobody.
     *
     * The runtime half of the guard in `OlsDriver::assertHasSystemUser()`.
     * That one stops the panel writing such a vhost; this one finds the ones
     * already on disk — written before the guard existed, adopted from a
     * brownfield server, or edited by hand.
     *
     * Worth being precise about what it costs when it is missing: every site
     * without it runs as the server's own user, so it is not one site losing
     * its boundary but all of them sharing one. Any of them can read the
     * others' `.env`, database credentials and uploads. Nothing about such a
     * server looks wrong from the outside.
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
     * The panel used to write `extprocessor lsphp84` into every site's vhost.
     * OpenLiteSpeed keeps one external app per name, so the first vhost loaded
     * supplied the extUser and socket for every site on that version: on a
     * real server (2026-09-24) three sites' PHP ran as a fourth site's user.
     * The panel now names each site's processor after the site; this finds the
     * vhosts still written the old way, or edited by hand.
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
     * Read from disk rather than from the database on purpose: the question is
     * what OpenLiteSpeed will actually do, and the file is the only thing that
     * answers it.
     *
     * @return array<string, string>
     */
    private function lsapiVhosts(): array
    {
        // Where this server actually keeps them, not where config guesses: a
        // migrated box uses the old panel's branded directory, and globbing the
        // configured one there finds nothing.
        $root = rtrim(app(OlsVhostLayout::class)->root(), '/');

        if ($root === '' || ! is_dir($root)) {
            return [];
        }

        // Both names a vhost may have. A server migrated from the old panel
        // calls it `main.conf`, and matching only ours meant this check saw no
        // sites at all there and reported clean — a check that cannot see is
        // worse than no check, because it answers.
        $paths = [];

        foreach (OlsVhostLayout::FILENAMES as $filename) {
            $paths = array_merge($paths, (array) glob($root.'/*/'.$filename));
        }

        $vhosts = [];

        foreach ($paths as $path) {
            $contents = @file_get_contents((string) $path);

            // Unreadable is not the same as missing. Reporting a permissions
            // problem as an isolation failure would send whoever reads it to
            // rewrite a vhost that is fine.
            if ($contents === false) {
                continue;
            }

            // Only vhosts that actually run PHP. A static or proxy site has no
            // extprocessor and needs no extUser.
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
