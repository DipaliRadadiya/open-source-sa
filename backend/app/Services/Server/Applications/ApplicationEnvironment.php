<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\EnvironmentOperationException;
use App\Models\Application;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use RuntimeException;

/**
 * Reads and writes one application's `.env`.
 *
 * Distinct from `App\Services\Server\EnvFile`, which edits keys in the *panel's own* `.env`. This
 * file belongs to the site's system user and is often 0600, so every read and write goes through
 * ServerOps, not PHP's filesystem functions.
 *
 * Whole-file writes, not key edits: rebuilding from parsed pairs would drop the user's comments,
 * grouping and ordering.
 */
class ApplicationEnvironment
{
    /**
     * Saves kept per application. Enough to undo a mistake, bounded. The activity log outlives
     * these files; the history says so per row.
     */
    public const KEEP_BACKUPS = 20;

    /** Refuse to read anything larger — a `.env` is kilobytes, not megabytes. */
    private const MAX_BYTES = 262144;

    /**
     * Exactly what {@see backup()} writes, and nothing else. One constant: this pattern is all that
     * stands between a user-supplied string and a path, so the read and restore guards must not drift.
     */
    public const BACKUP_NAME = '/^\.env\.bak-\d{8}-\d{6}(?:-\d{1,2})?$/';

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
        private SiteRootLock $rootLock,
        private RuntimeOwnership $ownership,
    ) {}

    /**
     * Resolved paths by application id, so one request does not re-stat.
     *
     * @var array<int, string>
     */
    private array $resolved = [];

    /**
     * Where this application's `.env` lives.
     *
     * Two rules. **Create where it is safe**: {@see Application::envPath()}, beside the code but
     * never inside a file-served directory. **Read where it actually is**: a `.env` already beside
     * the code is the one the framework loads, so that is the one to open; otherwise a save would
     * write a second copy that nothing reads.
     */
    public function path(Application $application): string
    {
        return $this->resolved[$application->id] ??= $this->resolve($application);
    }

    private function resolve(Application $application): string
    {
        $preferred = $application->envPath();
        $beside = $application->codePath().'/.env';

        if ($beside === $preferred) {
            return $preferred;
        }

        return $this->present($application, $beside, 'env_locate') ? $beside : $preferred;
    }

    /**
     * Where the file used to be, before it moved beside the code: older sites have it at the app
     * root. {@see migrateLegacy()}
     */
    private function legacyPath(Application $application): string
    {
        return $application->rootPath().'/.env';
    }

    public function exists(Application $application): bool
    {
        $this->migrateLegacy($application);

        return $this->present($application, $this->path($application), 'env_exists');
    }

    /**
     * Is that file there?
     *
     * `test -f` exits 1 both for an absent file and for a command that never ran (an outdated
     * sudoers grant, an untraversable home directory). `test` prints nothing on either outcome,
     * so anything on stderr means "no answer", which is worth a 500 with a reference rather than
     * a confident "no file".
     */
    private function present(Application $application, string $path, string $op): bool
    {
        // Exit 1 is the answer "no such file", not a failure (keeps it out of the error log).
        $result = $this->run($application,
            ['test', '-f', $path],
            $this->context($application, $op),
            timeout: 15,
            expectedExitCodes: [1],
        );

        if ($result->failed() && trim($result->errorOutput()) !== '') {
            throw new EnvironmentOperationException($result->reference);
        }

        return $result->ok;
    }

    /**
     * Move a pre-existing `.env` beside the code, once.
     *
     * A move, not a copy, and only when the new location is empty: two files would drift and the
     * one shown might not be the one the application reads. Silent when there is nothing to move.
     */
    private function migrateLegacy(Application $application): void
    {
        $legacy = $this->legacyPath($application);
        $current = $this->path($application);

        if ($legacy === $current) {
            return;
        }

        if (! $this->present($application, $legacy, 'env_legacy_check')) {
            return;
        }

        if ($this->present($application, $current, 'env_legacy_check')) {
            return;
        }

        // The legacy file is at the top of the site root, so moving it is a change to that
        // directory; see mutate().
        $this->rootLock->unlocked($application, fn () => $this->serverOps->run(
            ['mv', $legacy, $current],
            $this->context($application, 'env_legacy_move'),
            timeout: 15,
        ));
    }

    public function read(Application $application): string
    {
        $result = $this->run($application,
            ['cat', $this->path($application)],
            $this->context($application, 'env_read'),
            timeout: 30,
        );

        if ($result->failed()) {
            throw new RuntimeException('the environment file could not be read');
        }

        return $result->output();
    }

    /**
     * Replace the file, keeping a copy of what was there.
     *
     * The backup comes first and its failure is fatal: a save that silently skipped the copy would
     * break the promise that the previous version can be restored.
     *
     * Returns the name of the backup it took, or null when there was no file to copy (a first
     * save); the caller records it on the activity row.
     */
    public function write(Application $application, string $contents, bool $keepPrevious = true): ?string
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('the environment file is too large');
        }

        return $this->mutate($application, fn (): ?string => $this->replace($application, $contents, $keepPrevious));
    }

    /**
     * `$keepPrevious` is false for a clone (the replaced file is the source's copy, so keeping it
     * would be one more unused copy of its credentials) and when the user turned the copy off,
     * in which case nothing can be undone.
     */
    private function replace(Application $application, string $contents, bool $keepPrevious): ?string
    {
        $path = $this->path($application);
        $asRoot = ! $this->asUser($application);

        $backup = $keepPrevious && $this->exists($application) ? $this->backup($application) : null;

        // Written beside the target and renamed: a half-written `.env` stops the application booting.
        $temporary = $path.'.panel-tmp';

        $written = $this->run($application,
            ['tee', $temporary],
            $this->context($application, 'env_write'),
            timeout: 30,
            input: rtrim($contents, "\n")."\n",
        );

        if ($written->failed()) {
            throw new RuntimeException('the environment file could not be written');
        }

        // Ownership and mode before the rename, so the file is never in place readable by anyone
        // else nor unreadable by the site. Mode and group follow who runs PHP (RuntimeOwnership),
        // as in the installers; a flat 0600 would lock out a site running as the web server's account.
        $owner = $application->systemUser?->username;
        $group = $this->ownership->secretFileGroup($application);

        if ($owner !== null && ($asRoot || $group !== $owner)) {
            // Root's, since the user cannot give a file to the web server's group; `-h` so a link
            // swapped in for the temporary file is changed itself, not followed.
            $this->serverOps->run(['chown', '-h', $owner.':'.$group, $temporary], $this->context($application, 'env_chown'), timeout: 15);
        }

        $this->run($application, ['chmod', $this->ownership->secretFileMode($application), $temporary], $this->context($application, 'env_chmod'), timeout: 15);

        $moved = $this->run($application, ['mv', $temporary, $path], $this->context($application, 'env_swap'), timeout: 15);

        if ($moved->failed()) {
            $this->run($application, ['rm', '-f', $temporary], $this->context($application, 'env_cleanup'), timeout: 15);

            throw new RuntimeException('the environment file could not be replaced');
        }

        return $backup;
    }

    /**
     * Previous saves, newest first.
     *
     * @return array<int, array{name: string, created_at: string}>
     */
    public function backups(Application $application): array
    {
        $result = $this->run($application,
            ['find', dirname($this->path($application)), '-maxdepth', '1', '-name', '.env.bak-*', '-printf', '%f\n'],
            $this->context($application, 'env_backups'),
            timeout: 15,
        );

        if ($result->failed()) {
            return [];
        }

        $names = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $result->output()) ?: [])));

        // The timestamp is in the name, so sorting names sorts by time (mtime is rewritten by a restore).
        rsort($names);

        return array_map(fn (string $name): array => [
            'name' => $name,
            'created_at' => $this->timestampFrom($name),
        ], $names);
    }

    /**
     * Put a previous save back, after backing up the current state so a wrong restore is undoable.
     * Returns the name of that safety copy, like {@see write()}.
     */
    public function restore(Application $application, string $name, bool $keepCopy = true): ?string
    {
        if (preg_match(self::BACKUP_NAME, $name) !== 1) {
            // The name reaches a path: refuse anything not exactly what we write.
            throw new RuntimeException('that is not a known backup');
        }

        $directory = dirname($this->path($application));
        $source = $directory.'/'.$name;

        $exists = $this->run($application, ['test', '-f', $source], $this->context($application, 'env_backup_exists'), timeout: 15);

        if ($exists->failed()) {
            throw new RuntimeException('that backup no longer exists');
        }

        return $this->write($application, $this->readFile($application, $source), $keepCopy);
    }

    /**
     * The contents of one previous save.
     *
     * Same name validation as {@see restore()}. Returns null when the file is gone (pruned or never
     * taken), which is ordinary since the history outlives the files.
     */
    public function readBackup(Application $application, string $name): ?string
    {
        if (preg_match(self::BACKUP_NAME, $name) !== 1) {
            throw new RuntimeException('that is not a known backup');
        }

        $source = dirname($this->path($application)).'/'.$name;

        if (! $this->present($application, $source, 'env_backup_exists')) {
            return null;
        }

        return $this->readFile($application, $source);
    }

    private function backup(Application $application): string
    {
        $path = $this->path($application);
        $name = $this->unusedBackupName($application);

        $copied = $this->run($application,
            ['cp', '-p', $path, dirname($path).'/'.$name],
            $this->context($application, 'env_backup'),
            timeout: 15,
        );

        if ($copied->failed()) {
            throw new RuntimeException('the previous environment file could not be backed up, so nothing was changed');
        }

        $this->prune($application);

        return $name;
    }

    /**
     * A name no existing backup is using.
     *
     * The timestamp has second precision and two saves can land in one second; a collision would
     * make a history row restore somebody else's edit.
     */
    private function unusedBackupName(Application $application): string
    {
        $directory = dirname($this->path($application));
        $base = '.env.bak-'.now()->format('Ymd-His');

        if (! $this->present($application, $directory.'/'.$base, 'env_backup_name')) {
            return $base;
        }

        // Bounded: a hundred backups in one second means something a loop will not fix.
        for ($suffix = 2; $suffix <= 99; $suffix++) {
            $candidate = $base.'-'.$suffix;

            if (! $this->present($application, $directory.'/'.$candidate, 'env_backup_name')) {
                return $candidate;
            }
        }

        throw new RuntimeException('a backup name could not be reserved');
    }

    private function prune(Application $application): void
    {
        $surplus = array_slice($this->backups($application), self::KEEP_BACKUPS);

        foreach ($surplus as $backup) {
            $this->run($application,
                ['rm', '-f', dirname($this->path($application)).'/'.$backup['name']],
                $this->context($application, 'env_backup_prune'),
                timeout: 15,
            );
        }
    }

    private function readFile(Application $application, string $path): string
    {
        $result = $this->run($application, ['cat', $path], $this->context($application, 'env_read_backup'), timeout: 30);

        if ($result->failed()) {
            throw new RuntimeException('that backup could not be read');
        }

        return $result->output();
    }

    /** `.env.bak-20260804-132011` → `04-08-2026 13:20:11`, the panel's format. */
    private function timestampFrom(string $name): string
    {
        // Anchored on the `bak-` prefix, not the end, so a collision suffix (`…-132011-2`) still
        // reads its timestamp.
        if (preg_match('/bak-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/', $name, $m) !== 1) {
            return '';
        }

        return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$m[6]}";
    }

    /**
     * Whether the `.env` lives in a directory the site's user owns.
     *
     * Two homes, two rules. At the top of the site root the directory is root's and immutable
     * ({@see SiteRootLock}), so root may write there with the flag lifted. Beside the code, inside
     * `public_html`, the user owns the directory and can plant symlinks, so every command runs as
     * the user; a planted link then reaches only what the user could already touch.
     */
    private function asUser(Application $application): bool
    {
        // Not `path()`: resolving it runs a `test -f` that comes back through here, which would
        // recurse forever. Until resolved, only that one existence test runs.
        $path = $this->resolved[$application->id] ?? null;

        return $path !== null
            && $application->systemUser !== null
            && dirname($path) !== rtrim($application->rootPath(), '/');
    }

    /**
     * A save, restore or backup: the flag lifted when the file is at the top of the site root.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function mutate(Application $application, callable $operation): mixed
    {
        return $this->asUser($application)
            ? $operation()
            : $this->rootLock->unlocked($application, $operation);
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, mixed>  $context
     */
    /**
     * @param  array<int, int>  $expectedExitCodes
     */
    private function run(Application $application, array $command, array $context, int $timeout = 60, ?string $input = null, array $expectedExitCodes = []): ServerOpsResult
    {
        if ($this->asUser($application)) {
            $command = ['runuser', '-u', $application->systemUser->username, '--', ...$command];
        }

        return $this->serverOps->run($command, $context, timeout: $timeout, input: $input, expectedExitCodes: $expectedExitCodes);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }
}
