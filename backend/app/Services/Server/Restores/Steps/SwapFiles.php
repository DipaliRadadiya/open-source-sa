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
 * Two renames within the same parent directory, so the window in which the
 * site does not exist is measured in milliseconds regardless of how large it
 * is — a copy would take minutes and serve a half-written site throughout.
 *
 * The previous directory is *moved*, never deleted. It stays as
 * `.rollback-<id>` so that "the restore worked but the site is wrong" is still
 * recoverable by hand, which is a situation no amount of testing prevents.
 *
 * An application with a process (Node, and anything else systemd supervises)
 * is stopped first: swapping the directory under a running process leaves it
 * holding deleted file handles and serving code that no longer exists on disk.
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
        return $context->wantsFiles();
    }

    public function run(RestoreContext $context): void
    {
        // Whatever `ExtractArchive` unpacked, swapped back over the directory
        // that archive was made from — the same question, asked the same way,
        // so the two cannot disagree about which directory this restore is of.
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

        // The archive preserves the ownership it was created with, but a
        // restore onto a rebuilt server can land files owned by a uid that no
        // longer maps to this site's user — which shows up as a white screen
        // nobody connects to the restore.
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

        // Put the site back. Only when the swap actually happened — if the
        // move out of the way is what failed, the live directory never moved.
        $siteRoot = $this->roots->forBackup($context->backup, $context->application);

        if ($context->rollbackPath !== null && is_dir($context->rollbackPath) && ! is_dir($siteRoot)) {
            $this->move($context, $context->rollbackPath, $siteRoot, 'restore_rollback');
            $context->rollbackPath = null;
            $context->restore->update(['rollback_path' => null]);
        }

        // If the failure happened inside this step, RestartProcess never ran
        // and its cleanup will never fire — so the process this step stopped
        // would stay stopped. The flag keeps the two from starting it twice.
        if ($context->processStopped) {
            $this->processes->start($context->application);
            $context->processStopped = false;
        }
    }

    /**
     * Bring back what the backup deliberately left out.
     *
     * The swap replaces the whole site with the archive, and the archive does
     * not contain the paths its target excluded -- so a restore permanently
     * deleted them: exclude `wp-content/uploads` from a "Files and database"
     * backup, restore it, and every upload was gone, with Undo unable to help
     * (bug #78). Each excluded path that exists in the site as it was, and is
     * absent from what was restored, is carried over.
     *
     * Hard links (`cp -al`), not a move: no second copy of a large uploads
     * folder, and the copy kept aside for Undo stays whole.
     *
     * Patterns are the ones the archive was made with (manifest), or -- for a
     * backup taken before they were recorded -- the target's current ones.
     * Either is safe: only paths missing from the restored copy are filled.
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

        // -prune: an excluded directory is carried whole, its contents are
        // not listed one by one.
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
                // Not fatal: the restore itself succeeded, and the original is
                // still in the copy kept for Undo. Said in the run's log.
                $context->restore->update(['reason' => trim(($context->restore->reason ?? '')."\n".__('backup.errors.excluded_not_kept', ['path' => $relative]))]);
            }
        }
    }

    private function move(RestoreContext $context, string $from, string $to, string $op): void
    {
        // Both ends are entries of the site root — the live directory, the
        // aside copy, the staged one — so each move changes the top level of a
        // directory {@see SiteRootLock} keeps immutable.
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
