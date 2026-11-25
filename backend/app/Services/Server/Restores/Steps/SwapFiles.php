<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Moves the restored copy into place, and the live one out of the way.
 *
 * Two renames within the same parent directory, so the site is missing only for milliseconds;
 * a copy would take minutes and serve a half-written site.
 *
 * The previous directory is moved to `.rollback-<id>`, never deleted, so a bad restore stays
 * recoverable. A supervised process (Node, systemd) is stopped first: swapping the directory
 * under it leaves it holding deleted file handles.
 */
class SwapFiles implements RestoreStep
{
    public function __construct(
        private ServerOps $serverOps,
        private BackupRoot $roots,
        private ProcessSupervisor $processes,
        private SiteRootLock $rootLock,
    ) {}

    public function key(): string
    {
        return 'swap_files';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        // `wantsConfig()` too: a container site's compose files and `.env` live in the renamed root.
        return $context->wantsFiles() || $context->wantsConfig();
    }

    public function run(RestoreContext $context): void
    {
        // Same lookup as `ExtractArchive`, so both agree on which directory is restored.
        $siteRoot = $this->roots->forBackup($context->backup, $context->application);
        $staged = $context->stagingDirectory.'/'.basename($siteRoot);
        $rollback = dirname($siteRoot).'/.rollback-'.$context->restore->id;

        if (! is_dir($staged)) {
            throw new RuntimeException('the restored copy is missing');
        }

        if ($this->processes->runs($context->application)) {
            $this->processes->stop($context->application);
            $context->processStopped = true;
        }

        if (is_dir($siteRoot)) {
            $this->move($context, $siteRoot, $rollback, 'restore_rollback_aside');
            $context->rollbackPath = $rollback;
            $context->restore->update(['rollback_path' => $rollback]);
        }

        $this->move($context, $staged, $siteRoot, 'restore_swap');

        if ($context->rollbackPath !== null) {
            $this->keepExcluded($context, $context->rollbackPath, $siteRoot);
        }

        // The archive keeps its original ownership; on a rebuilt server that uid may no longer
        // map to this site's user (white screen).
        $user = $context->application->systemUser?->username;

        if ($user !== null) {
            $this->serverOps->run(
                ['chown', '-R', $user.':'.$user, $siteRoot],
                ['feature' => 'backup', 'op' => 'restore_chown', 'application' => $context->application->id],
                timeout: 600,
            );
        }
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        if (! $failed) {
            return;
        }

        // Put the site back, only if the swap happened (the live directory moved).
        $siteRoot = $this->roots->forBackup($context->backup, $context->application);

        if ($context->rollbackPath !== null && is_dir($context->rollbackPath) && ! is_dir($siteRoot)) {
            $this->move($context, $context->rollbackPath, $siteRoot, 'restore_rollback');
            $context->rollbackPath = null;
            $context->restore->update(['rollback_path' => null]);
        }

        // RestartProcess never ran if this step failed, so restart here; the flag avoids a double start.
        if ($context->processStopped) {
            $this->processes->start($context->application);
            $context->processStopped = false;
        }
    }

    /**
     * Bring back what the backup deliberately left out.
     *
     * The archive lacks the paths its target excluded, so the swap would delete them for good.
     * Each excluded path present in the old site but absent from the restored copy is carried over.
     *
     * Hard links (`cp -al`): no second copy of a large folder, and the Undo copy stays whole.
     * Patterns come from the manifest, or the target's current ones for older backups.
     */
    private function keepExcluded(RestoreContext $context, string $previous, string $siteRoot): void
    {
        $patterns = $context->backup->manifest['file_excludes']
            ?? (array) ($context->backup->target?->file_excludes ?? []);
        $patterns = array_values(array_filter(array_map(
            fn ($pattern) => ltrim(preg_replace('#^\./#', '', trim((string) $pattern)) ?? '', '/'),
            (array) $patterns,
        ), fn (string $pattern) => $pattern !== ''));

        if ($patterns === []) {
            return;
        }

        $op = ['feature' => 'backup', 'op' => 'restore_keep_excluded', 'application' => $context->application->id];

        $match = [];
        foreach ($patterns as $pattern) {
            if ($match !== []) {
                $match[] = '-o';
            }
            array_push($match, '-path', $previous.'/'.$pattern, '-o', '-path', $previous.'/*/'.$pattern);
        }

        // -prune: an excluded directory is carried whole.
        $found = $this->serverOps->run(
            ['find', $previous, '(', ...$match, ')', '-prune', '-print0'],
            $op,
            timeout: 600,
        );

        foreach (array_filter(explode("\0", $found->output())) as $path) {
            if (! str_starts_with($path, $previous.'/')) {
                continue;
            }
            $relative = substr($path, strlen($previous) + 1);
            $target = $siteRoot.'/'.$relative;

            // Already restored from the archive: the archive wins.
            if ($this->serverOps->probe(['test', '-e', $target], $op)->ok) {
                continue;
            }

            $this->serverOps->run(['mkdir', '-p', dirname($target)], $op);
            $copied = $this->serverOps->run(['cp', '-al', $path, $target], $op, timeout: 600);

            if ($copied->failed()) {
                // Not fatal: the original is still in the Undo copy. Reported in the run's log.
                $context->restore->update(['reason' => trim(($context->restore->reason ?? '')."\n".__('backup.errors.excluded_not_kept', ['path' => $relative]))]);
            }
        }
    }

    private function move(RestoreContext $context, string $from, string $to, string $op): void
    {
        // Each move changes the top level of a directory {@see SiteRootLock} keeps immutable.
        $result = $this->rootLock->unlocked($context->application, fn () => $this->serverOps->run(
            ['mv', $from, $to],
            ['feature' => 'backup', 'op' => $op, 'application' => $context->application->id],
            timeout: 600,
        ));

        if ($result->failed()) {
            throw new RuntimeException("could not move {$from} to {$to}");
        }
    }
}
