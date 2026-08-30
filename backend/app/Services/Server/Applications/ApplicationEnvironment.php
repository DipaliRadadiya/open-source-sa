<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Reads and writes one application's `.env`.
 *
 * Distinct from `App\Services\Server\EnvFile`, which edits single keys in the
 * *panel's own* `.env`. This one handles a file the panel does not own: it
 * belongs to the site's system user, is often 0600, and is edited wholesale by
 * a human rather than one key at a time. Every read and write therefore goes
 * through ServerOps rather than PHP's filesystem functions — the panel account
 * cannot open it directly.
 *
 * Whole-file writes, not key edits: comments, blank-line grouping and ordering
 * are the user's, and rebuilding the file from parsed pairs would quietly
 * throw all three away.
 */
class ApplicationEnvironment
{
    /** Saves kept per application. Enough to undo a mistake, bounded. */
    public const KEEP_BACKUPS = 5;

    /** Refuse to read anything larger — a `.env` is kilobytes, not megabytes. */
    private const MAX_BYTES = 262144;

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
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
     * Two rules, because they answer different questions. **Create where it is
     * safe** — {@see Application::envPath()}, beside the code but never inside
     * a file-served directory. **Read where it actually is**: if a `.env`
     * already sits beside the code, that is the file the framework loads, and
     * that is the one this screen must open.
     *
     * Without the second rule the panel reported "no environment file" for a
     * site whose `.env` was plainly visible in the file manager, and a save
     * would have written a second copy one directory up that nothing reads.
     * Declining to show an already-exposed file does not unexpose it; it only
     * hides it from the one person who could move it.
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

        $exists = $this->serverOps->run(
            ['test', '-f', $beside],
            $this->context($application, 'env_locate'),
            timeout: 15,
        )->ok;

        return $exists ? $beside : $preferred;
    }

    /**
     * Where the file used to be, before it moved beside the code.
     *
     * Sites deployed before that change have their `.env` at the app root, and
     * the panel would otherwise open a different, empty file and offer to
     * create one — quietly stranding the real settings one directory up.
     * {@see migrateLegacy()}
     */
    private function legacyPath(Application $application): string
    {
        return $application->rootPath().'/.env';
    }

    public function exists(Application $application): bool
    {
        $this->migrateLegacy($application);

        return $this->serverOps->run(
            ['test', '-f', $this->path($application)],
            $this->context($application, 'env_exists'),
            timeout: 15,
        )->ok;
    }

    /**
     * Move a pre-existing `.env` beside the code, once.
     *
     * A move rather than a copy, and only when the new location is empty:
     * leaving both would give the site two `.env` files whose contents drift,
     * and the one the panel showed would not be the one the application read —
     * which is the bug this whole change exists to remove.
     *
     * Silent when there is nothing to move, which is every site created after
     * this shipped.
     */
    private function migrateLegacy(Application $application): void
    {
        $legacy = $this->legacyPath($application);
        $current = $this->path($application);

        if ($legacy === $current) {
            return;
        }

        $hasLegacy = $this->serverOps->run(
            ['test', '-f', $legacy],
            $this->context($application, 'env_legacy_check'),
            timeout: 15,
        )->ok;

        if (! $hasLegacy) {
            return;
        }

        $hasCurrent = $this->serverOps->run(
            ['test', '-f', $current],
            $this->context($application, 'env_legacy_check'),
            timeout: 15,
        )->ok;

        if ($hasCurrent) {
            return;
        }

        $this->serverOps->run(
            ['mv', $legacy, $current],
            $this->context($application, 'env_legacy_move'),
            timeout: 15,
        );
    }

    public function read(Application $application): string
    {
        $result = $this->serverOps->run(
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
     * The backup comes first and its failure is fatal: this screen's whole
     * safety story is "you can get the previous version back", and a save that
     * silently skipped the copy would be the one time that promise mattered.
     */
    public function write(Application $application, string $contents): void
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('the environment file is too large');
        }

        $path = $this->path($application);
        $user = $application->systemUser?->username;

        if ($this->exists($application)) {
            $this->backup($application);
        }

        // Written beside the target and renamed. A half-written `.env` is an
        // application that will not boot at all, which is worse than any value
        // in it being wrong.
        $temporary = $path.'.panel-tmp';

        $written = $this->serverOps->run(
            ['tee', $temporary],
            $this->context($application, 'env_write'),
            timeout: 30,
            input: rtrim($contents, "\n")."\n",
        );

        if ($written->failed()) {
            throw new RuntimeException('the environment file could not be written');
        }

        // Ownership and mode before the rename, so the file is never briefly
        // in place while readable by anyone else.
        if ($user !== null) {
            $this->serverOps->run(['chown', $user.':'.$user, $temporary], $this->context($application, 'env_chown'), timeout: 15);
        }

        $this->serverOps->run(['chmod', '0600', $temporary], $this->context($application, 'env_chmod'), timeout: 15);

        $moved = $this->serverOps->run(['mv', $temporary, $path], $this->context($application, 'env_swap'), timeout: 15);

        if ($moved->failed()) {
            $this->serverOps->run(['rm', '-f', $temporary], $this->context($application, 'env_cleanup'), timeout: 15);

            throw new RuntimeException('the environment file could not be replaced');
        }
    }

    /**
     * Previous saves, newest first.
     *
     * @return array<int, array{name: string, created_at: string}>
     */
    public function backups(Application $application): array
    {
        $result = $this->serverOps->run(
            ['find', dirname($this->path($application)), '-maxdepth', '1', '-name', '.env.bak-*', '-printf', '%f\n'],
            $this->context($application, 'env_backups'),
            timeout: 15,
        );

        if ($result->failed()) {
            return [];
        }

        $names = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $result->output()) ?: [])));

        // The timestamp is in the name, so sorting the names sorts by time —
        // no stat call per file, and no dependence on mtime, which a restore
        // would rewrite.
        rsort($names);

        return array_map(fn (string $name): array => [
            'name' => $name,
            'created_at' => $this->timestampFrom($name),
        ], $names);
    }

    /**
     * Put a previous save back. Taking a backup of the current state first, so
     * restoring the wrong one is itself undoable.
     */
    public function restore(Application $application, string $name): void
    {
        if (preg_match('/^\.env\.bak-\d{8}-\d{6}$/', $name) !== 1) {
            // The name reaches a path. Anything not matching exactly what we
            // write is refused rather than sanitised.
            throw new RuntimeException('that is not a known backup');
        }

        $directory = dirname($this->path($application));
        $source = $directory.'/'.$name;

        $exists = $this->serverOps->run(['test', '-f', $source], $this->context($application, 'env_backup_exists'), timeout: 15);

        if ($exists->failed()) {
            throw new RuntimeException('that backup no longer exists');
        }

        $this->write($application, $this->readFile($application, $source));
    }

    private function backup(Application $application): void
    {
        $path = $this->path($application);
        $name = '.env.bak-'.now()->format('Ymd-His');

        $copied = $this->serverOps->run(
            ['cp', '-p', $path, dirname($path).'/'.$name],
            $this->context($application, 'env_backup'),
            timeout: 15,
        );

        if ($copied->failed()) {
            throw new RuntimeException('the previous environment file could not be backed up, so nothing was changed');
        }

        $this->prune($application);
    }

    private function prune(Application $application): void
    {
        $surplus = array_slice($this->backups($application), self::KEEP_BACKUPS);

        foreach ($surplus as $backup) {
            $this->serverOps->run(
                ['rm', '-f', dirname($this->path($application)).'/'.$backup['name']],
                $this->context($application, 'env_backup_prune'),
                timeout: 15,
            );
        }
    }

    private function readFile(Application $application, string $path): string
    {
        $result = $this->serverOps->run(['cat', $path], $this->context($application, 'env_read_backup'), timeout: 30);

        if ($result->failed()) {
            throw new RuntimeException('that backup could not be read');
        }

        return $result->output();
    }

    /** `.env.bak-20260804-132011` → `04-08-2026 13:20:11`, the panel's format. */
    private function timestampFrom(string $name): string
    {
        if (preg_match('/(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $name, $m) !== 1) {
            return '';
        }

        return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$m[6]}";
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }
}
