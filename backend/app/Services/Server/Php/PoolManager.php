<?php

namespace App\Services\Server\Php;

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * One PHP-FPM pool per application.
 *
 * The panel creates a Linux user per site and gives it the files — but until
 * now the PHP that serves the site ran as www-data, the same account as every
 * other site on the box. So one compromised plugin could read every other
 * customer's `.env`, and the per-site users protected nothing at the point
 * where it counts. A pool per site is what makes them mean something.
 *
 * Everything here is FPM-only by construction. OpenLiteSpeed has no pools at
 * all — it spawns LSPHP itself — so the stack answers `null` for a pool path
 * and this class is never reached.
 */
class PoolManager
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private PhpStackManager $stacks,
    ) {}

    /**
     * Named after the app, not an opaque `sv-app-{id}` — the id guaranteed
     * uniqueness, but the slug already carries that same guarantee (it's
     * what vhosts and logs are named after, for the identical reason: see
     * AbstractWebServerDriver::fileName()) and is a name a human reading
     * pool.d/ can actually place. Falls back to the domain for a row that
     * predates the slug column, matching fileName()'s own fallback.
     */
    public function poolName(Application $application): string
    {
        return (string) ($application->slug ?: $application->domain);
    }

    /**
     * The socket this site's vhost should talk to.
     *
     * Its own pool once isolated; the server-wide one until then. Every PHP
     * site on an un-upgraded server keeps working exactly as it did, which is
     * what makes isolation safe to roll out one site at a time.
     */
    public function socketFor(Application $application): string
    {
        if ($application->isolated_at !== null && $this->supported()) {
            return $this->socketPath($application);
        }

        $version = $application->php_version ?: config('server.default_php_version');
        $dir = rtrim((string) config('server.php_socket_dir', '/run/php'), '/');

        return "{$dir}/php{$version}-fpm.sock";
    }

    public function socketPath(Application $application): string
    {
        $dir = rtrim((string) config('server.php_socket_dir', '/run/php'), '/');

        return $dir.'/'.$this->poolName($application).'.sock';
    }

    /** Null when this server's PHP stack has no pools (OpenLiteSpeed). */
    public function poolPath(Application $application, ?string $version = null): ?string
    {
        $stack = $this->stacks->stack();

        if ($stack->key() !== 'fpm') {
            return null;
        }

        $version = $version ?: ($application->php_version ?: config('server.default_php_version'));
        $root = rtrim((string) config('server.php_dir', '/etc/php'), '/');

        return "{$root}/{$version}/fpm/pool.d/".$this->poolName($application).'.conf';
    }

    public function supported(): bool
    {
        return $this->stacks->stack()->key() === 'fpm';
    }

    /**
     * Write the pool and make it live.
     *
     * The order is the safety property. `php-fpm -t` runs **before** any
     * reload, because a pool file FPM cannot parse stops the whole daemon
     * starting — which takes down every PHP site on the server, not just this
     * one. A failed test removes the file and reloads nothing.
     *
     * @return array{ok: bool, reason: ?string, reference: ?string}
     */
    public function apply(Application $application, ApplicationPhpSettings $settings): array
    {
        $path = $this->poolPath($application);

        if ($path === null) {
            return ['ok' => false, 'reason' => 'unsupported_stack', 'reference' => null];
        }

        // FPM resolves `user =` to a uid at startup and refuses to initialise
        // if it cannot — and that refusal is server-wide, not per-pool. A pool
        // naming an account that does not exist therefore does not merely fail
        // to work: it stops php-fpm coming back after a restart, taking every
        // PHP site on the box with it. Checked before writing, so the file is
        // never created in the first place.
        $account = $application->systemUser?->username;

        if ($account === null || ! $this->accountExists($account)) {
            return ['ok' => false, 'reason' => 'missing_account', 'reference' => null];
        }

        $version = $application->php_version ?: config('server.default_php_version');
        $previous = $this->read($path);

        $this->ensureSiteDirectories($application);

        $written = $this->files->put($path, $this->render($application, $settings), $this->context($application, 'pool_write'));

        if ($written->failed()) {
            return ['ok' => false, 'reason' => 'write_failed', 'reference' => $written->reference];
        }

        $test = $this->test($version);

        if ($test->failed()) {
            // Put back exactly what was there — or remove the file if this was
            // the first attempt. Either way nothing is reloaded, so the server
            // is still serving what it was a moment ago.
            $this->restore($application, $path, $previous);

            return ['ok' => false, 'reason' => 'config_test_failed', 'reference' => $test->reference];
        }

        $reload = $this->reload($version);

        if ($reload->failed()) {
            $this->restore($application, $path, $previous);
            $this->reload($version);

            return ['ok' => false, 'reason' => 'reload_failed', 'reference' => $reload->reference];
        }

        return ['ok' => true, 'reason' => null, 'reference' => null];
    }

    /**
     * Whether this OS account actually exists on the server.
     *
     * The panel's `system_users` table records what it created; it is not
     * proof. A server rebuilt under a surviving database, an adopted box, or a
     * `useradd` that failed somewhere the row outlived all produce a username
     * with no passwd entry — {@see CrontabManager} learned the same thing and
     * asks `getent` for the same reason.
     */
    private function accountExists(string $username): bool
    {
        return $this->serverOps->run(
            ['getent', 'passwd', $username],
            ['feature' => 'php', 'op' => 'pool_account_check'],
            timeout: 15,
        )->ok;
    }

    /**
     * Pool files on disk whose `user` no longer resolves to an account.
     *
     * Read from the filesystem rather than from the applications table on
     * purpose: the pools that matter here are exactly the ones no application
     * row claims any more. One of these stops php-fpm initialising — not the
     * pool, the daemon — so it takes every PHP site on the server down at the
     * next restart, and until then it fails the `php-fpm -t` inside every
     * other site's {@see apply()}, which reports the error against whichever
     * innocent site was being created at the time.
     *
     * @return array<int, array{path: string, user: string}>
     */
    public function unresolvableAccounts(): array
    {
        $root = rtrim((string) config('server.php_dir', '/etc/php'), '/');

        $found = $this->serverOps->run(
            ['find', $root, '-type', 'f', '-name', '*.conf', '-path', '*/fpm/pool.d/*'],
            ['feature' => 'php', 'op' => 'pool_scan'],
            timeout: 30,
        );

        if ($found->failed()) {
            return [];
        }

        $files = array_values(array_filter(array_map('trim', explode("\n", (string) $found->output()))));

        if ($files === []) {
            return [];
        }

        // One grep for every pool rather than one read each: a box with a
        // dozen sites would otherwise be a dozen elevated commands to answer
        // a question that is usually "no".
        $users = $this->serverOps->run(
            ['grep', '-H', '-m', '1', '-E', '^[[:space:]]*user[[:space:]]*=', ...$files],
            ['feature' => 'php', 'op' => 'pool_scan_users'],
            timeout: 30,
        );

        $orphans = [];
        $resolved = [];

        foreach (explode("\n", (string) $users->output()) as $line) {
            if (preg_match('/^(.+?):\s*user\s*=\s*(\S+)/', trim($line), $m) !== 1) {
                continue;
            }

            [, $path, $user] = $m;

            $resolved[$user] ??= $this->accountExists($user);

            if (! $resolved[$user]) {
                $orphans[] = ['path' => $path, 'user' => $user];
            }
        }

        return $orphans;
    }

    /** Remove the pool and reload. Used when un-isolating or deleting a site. */
    public function remove(Application $application, ?string $version = null): void
    {
        $path = $this->poolPath($application, $version);

        if ($path === null) {
            return;
        }

        $version = $version ?: ($application->php_version ?: config('server.default_php_version'));

        $this->files->delete($path, $this->context($application, 'pool_remove'));
        $this->reload($version);
    }

    public function exists(Application $application): bool
    {
        $path = $this->poolPath($application);

        return $path !== null && $this->serverOps->probe(
            ['test', '-f', $path],
            $this->context($application, 'pool_exists'),
            timeout: 15,
        )->ok;
    }

    /**
     * Whether the file on disk still matches what the panel would write.
     *
     * Reported rather than corrected: someone who hand-edited the pool should
     * be told their changes will be overwritten *before* they press save, not
     * discover it afterwards.
     */
    public function managed(Application $application, ApplicationPhpSettings $settings): bool
    {
        $path = $this->poolPath($application);

        if ($path === null || ! $this->exists($application)) {
            return true;
        }

        return trim((string) $this->read($path)) === trim($this->render($application, $settings));
    }

    public function render(Application $application, ApplicationPhpSettings $settings): string
    {
        $effective = $settings->effective();
        $root = $this->appRoot($application);
        $children = max(1, (int) $effective['pm_max_children']);

        return View::make('server.pools.fpm', [
            'pool' => $this->poolName($application),
            'user' => $application->systemUser?->username ?? 'www-data',
            'socket' => $this->socketPath($application),
            'webServerUser' => (string) config('server.web_server_user', 'www-data'),

            'pmType' => $effective['pm_type'],
            'pmMaxChildren' => $children,
            'pmMaxRequests' => (int) $effective['pm_max_requests'],
            // Derived from max_children rather than asked for. These three have
            // to stay consistent with it and with each other; exposing them is
            // mostly a way to produce a pool that will not start.
            'pmStartServers' => max(1, (int) ceil($children / 2)),
            'pmMinSpare' => max(1, (int) ceil($children / 4)),
            'pmMaxSpare' => max(2, (int) ceil($children * 3 / 4)),

            'sessionPath' => $this->sessionPath($application),
            'errorLog' => $this->errorLogPath($application),

            'memoryLimit' => $effective['memory_limit'],
            'uploadMaxFilesize' => $effective['upload_max_filesize'],
            'postMaxSize' => $effective['post_max_size'],
            'maxExecutionTime' => (int) $effective['max_execution_time'],
            'maxInputTime' => (int) $effective['max_input_time'],
            'maxInputVars' => (int) $effective['max_input_vars'],
            'sessionGcMaxlifetime' => (int) $effective['session_gc_maxlifetime'],
            'phpTimezone' => $effective['php_timezone'],
            'autoPrependFile' => $effective['auto_prepend_file'],
            'allowUrlFopen' => (bool) $effective['allow_url_fopen'],

            'openBasedir' => $effective['open_basedir_enabled']
                ? $this->openBasedir($application, (string) ($effective['open_basedir_paths'] ?? ''))
                : null,
            'disableFunctions' => $effective['disable_functions'],
            'additionalDirectives' => $effective['additional_directives'],
        ])->render();
    }

    public function test(string $version): ServerOpsResult
    {
        return $this->serverOps->run(
            ['php-fpm'.$version, '-t'],
            ['feature' => 'php', 'op' => 'pool_test', 'version' => $version],
            timeout: 60,
        );
    }

    public function reload(string $version): ServerOpsResult
    {
        // Reload, never restart: a restart drops every in-flight request on
        // every site the daemon serves.
        return $this->serverOps->run(
            ['systemctl', 'reload', 'php'.$version.'-fpm'],
            ['feature' => 'php', 'op' => 'pool_reload', 'version' => $version],
            timeout: 60,
        );
    }

    public function sessionPath(Application $application): string
    {
        return $this->appRoot($application).'/.panel/sessions';
    }

    public function errorLogPath(Application $application): string
    {
        return $this->appRoot($application).'/.panel/php-error.log';
    }

    /**
     * The session and log directories, owned by the site.
     *
     * Created before the pool goes live: FPM will not start a pool whose
     * session path does not exist, and a site that cannot write sessions logs
     * nobody in.
     */
    private function ensureSiteDirectories(Application $application): void
    {
        $user = $application->systemUser?->username;
        $sessions = $this->sessionPath($application);

        $this->serverOps->run(['mkdir', '-p', $sessions], $this->context($application, 'pool_dirs'), timeout: 30);

        if ($user !== null) {
            $this->serverOps->run(
                ['chown', '-R', $user.':'.$user, dirname($sessions)],
                $this->context($application, 'pool_dirs_own'),
                timeout: 30,
            );
        }

        // 0700: session files are as sensitive as the cookies that name them.
        $this->serverOps->run(['chmod', '0700', $sessions], $this->context($application, 'pool_dirs_mode'), timeout: 15);
    }

    private function restore(Application $application, string $path, ?string $previous): void
    {
        if ($previous === null) {
            $this->files->delete($path, $this->context($application, 'pool_rollback_delete'));

            return;
        }

        $this->files->put($path, $previous, $this->context($application, 'pool_rollback'));
    }

    /**
     * Memoized for the life of this instance — one request. `managed()` and
     * the live open_basedir lookup both want the same file, and each read is
     * an elevated `cat`; without this, opening one PHP screen shells out
     * twice for identical bytes.
     *
     * @var array<string, string|null>
     */
    private array $readCache = [];

    private function read(string $path): ?string
    {
        if (array_key_exists($path, $this->readCache)) {
            return $this->readCache[$path];
        }

        // Probing first: a missing file is a normal state for an isolated site
        // that has not had a pool written yet — not an error.  We `cat` only
        // when the file is confirmed present, so a genuine read failure (wrong
        // permissions, inode vanished mid-check) is the only thing that returns
        // null.  See also `ServerOps::probe()` which marks exit-1 as expected.
        $probe = $this->serverOps->probe(['test', '-f', $path], ['feature' => 'php', 'op' => 'pool_exists']);

        if (! $probe->ok) {
            return $this->readCache[$path] = null;
        }

        $result = $this->serverOps->run(['cat', $path], ['feature' => 'php', 'op' => 'pool_read'], timeout: 30);

        return $this->readCache[$path] = $result->failed() ? null : $result->output();
    }

    /**
     * The open_basedir the pool file on disk actually sets, or null when the
     * file does not exist or does not set one.
     *
     * Read rather than derived, because the two can differ and the difference
     * is the whole point: someone can hand-edit the pool, or put their own
     * `php_admin_value[open_basedir]` in the additional-directives box, where
     * it lands after ours and wins. Deriving would have the panel report a
     * restriction PHP is not applying.
     */
    public function liveOpenBasedir(Application $application): ?string
    {
        $path = $this->livePoolPath($application);

        if ($path === null) {
            return null;
        }

        $contents = $this->read($path);

        if ($contents === null) {
            return null;
        }

        // The last match wins, the way FPM itself resolves a repeated key —
        // taking the first would report the panel's line while the server
        // obeys someone else's. `php_value` as well as `php_admin_value`:
        // both set it, and a config the panel did not write may use either.
        preg_match_all(
            '/^\s*php_(?:admin_)?value\s*\[\s*open_basedir\s*\]\s*=\s*(.+?)\s*$/mi',
            $contents,
            $matches,
        );

        $value = $matches[1] === [] ? null : trim((string) end($matches[1]));

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Take over an open_basedir that was already on the box.
     *
     * A server migrated from another panel has real values in its pool files,
     * and the settings row that replaces them starts empty — so writing our
     * own pool would drop a restriction the owner deliberately set, without
     * saying so. Read what is there, subtract the paths we supply ourselves,
     * and keep the remainder as the user's own.
     *
     * Runs once, at the point the panel takes ownership of a site's pool.
     * After that the row is authoritative and the file is its artefact.
     *
     * @return array{adopted: bool, kept: array<int, string>, dropped: array<int, string>}
     */
    public function adoptOpenBasedir(Application $application, ApplicationPhpSettings $settings): array
    {
        $none = ['adopted' => false, 'kept' => [], 'dropped' => []];

        // Already answered by the user — never overwrite a real choice with
        // whatever a previous panel happened to leave on disk.
        if ($settings->getAttribute('open_basedir_paths') !== null || $settings->open_basedir_enabled) {
            return $none;
        }

        $live = $this->liveOpenBasedir($application);

        if ($live === null) {
            return $none;
        }

        $ours = array_map('trim', explode(':', $this->openBasedir($application, '')));
        $shared = array_map(
            fn (string $path): string => rtrim($path, '/'),
            (array) config('server.shared_session_dirs', []),
        );

        $kept = [];
        $dropped = [];

        foreach (explode(':', $live) as $path) {
            $path = trim($path);

            if ($path === '' || in_array($path, $ours, true)) {
                continue;
            }

            // A server-wide session directory is the one thing not carried
            // over: importing it would hand this site read access to every
            // other site's sessions, and its own session directory is already
            // in the base paths.
            if (in_array(rtrim($path, '/'), $shared, true)) {
                $dropped[] = $path;

                continue;
            }

            $kept[] = $path;
        }

        $settings->open_basedir_enabled = true;
        $settings->open_basedir_paths = $kept === [] ? null : implode(':', $kept);
        $settings->application_id = $application->id;
        $settings->save();

        Log::channel('server-ops')->info('adopted an existing open_basedir', [
            'feature' => 'php',
            'op' => 'open_basedir_adopt',
            'application' => $application->id,
            'kept' => $kept,
            'dropped' => $dropped,
        ]);

        return ['adopted' => true, 'kept' => $kept, 'dropped' => $dropped];
    }

    /**
     * The pool file that actually serves this site, whatever it is called.
     *
     * `poolPath()` builds a name from the panel's own slug, which is right
     * for a site the panel created and wrong for every site it did not. A box
     * migrated from another panel names its pools that panel's way — so the
     * constructed path points at nothing, `liveOpenBasedir()` reads null, and
     * the adoption built to rescue exactly those sites quietly did nothing.
     *
     * So: try our name first (cheap, and correct for our own sites), then
     * look for a pool that declares this site's Linux user. That is the one
     * property a pool cannot fake — it is what FPM drops privileges to.
     */
    public function livePoolPath(Application $application): ?string
    {
        $ours = $this->poolPath($application);

        if ($ours !== null && $this->readable($ours)) {
            return $ours;
        }

        $username = $application->systemUser?->username;

        if ($username === null || $ours === null) {
            return null;
        }

        $directory = dirname($ours);

        $listing = $this->serverOps->run(
            ['find', $directory, '-maxdepth', '1', '-type', 'f'],
            ['feature' => 'php', 'op' => 'locate_pool', 'application' => $application->id],
            timeout: 30,
        );

        if ($listing->failed()) {
            return null;
        }

        foreach (preg_split('/\r?\n/', trim($listing->output())) ?: [] as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $contents = $this->read($candidate);

            if ($contents === null) {
                continue;
            }

            // `user = <name>` is what the pool runs as. Matched on its own
            // line so a path that merely contains the username elsewhere in
            // the file cannot claim the pool.
            if (preg_match('/^\s*user\s*=\s*'.preg_quote($username, '/').'\s*$/m', $contents)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A pool file's contents, memoized. Public so the sync discoverer reads
     * through the same cache rather than shelling out for the same bytes a
     * second time on the same request.
     */
    public function readPool(string $path): ?string
    {
        return $this->read($path);
    }

    private function readable(string $path): bool
    {
        return $this->read($path) !== null;
    }

    /**
     * What we would set if the user turned this on and added nothing.
     *
     * Offered as a starting point when open_basedir is off, so the screen can
     * show the value rather than asking someone to guess it — the same shape
     * as the disable_functions presets. It is deliberately the bare minimum
     * the site needs to keep working, not a guess at what it might want.
     */
    public function recommendedOpenBasedir(Application $application): string
    {
        return $this->openBasedir($application, '');
    }

    /**
     * The full open_basedir value: what the site always needs, plus whatever
     * the user added.
     *
     * The first three are not negotiable. Without the app root PHP cannot read
     * its own code; without the session directory every login on the site
     * stops working the moment this is switched on, which is the classic way
     * open_basedir gets blamed for "the panel broke my site"; /tmp is where
     * uploads land before PHP moves them.
     *
     * Note the session directory is this site's own, under its app root — not
     * a server-wide `/var/lib/php/sessions`. Naming a shared session directory
     * here would let every site on the box read every other site's session
     * files, which is the isolation per-app pools exist to provide.
     *
     * Empty entries are dropped rather than joined blindly: a trailing colon
     * leaves an empty path component, and what PHP does with one is version
     * dependent and never what anybody meant.
     */
    public function openBasedirFor(Application $application, ApplicationPhpSettings $settings): ?string
    {
        $effective = $settings->effective();

        return $effective['open_basedir_enabled']
            ? $this->openBasedir($application, (string) ($effective['open_basedir_paths'] ?? ''))
            : null;
    }

    private function openBasedir(Application $application, string $extra): string
    {
        $paths = array_merge(
            [$this->appRoot($application), $this->sessionPath($application), '/tmp'],
            preg_split('/[:\n,]+/', $extra) ?: [],
        );

        $paths = array_values(array_unique(array_filter(array_map('trim', $paths), fn (string $p): bool => $p !== '')));

        return implode(':', $paths);
    }

    /**
     * The app's own root — deliberately NOT the document root.
     *
     * Sessions and the PHP error log must sit outside the directory the web
     * server serves, or they are downloadable. This was named documentRoot()
     * while returning something else, which is how `.panel` ended up meaning
     * two different directories in two different services.
     */
    private function appRoot(Application $application): string
    {
        return $application->rootPath();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'php', 'op' => $op, 'application' => $application->id];
    }
}
