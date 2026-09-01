<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\Process;

/**
 * Can an in-place update run on this box right now?
 *
 * Every check is read-only and cheap. The point is to fail *before* anything
 * is touched: once the panel is in maintenance mode with half its dependencies
 * reinstalled, "not enough disk" is an outage rather than a message.
 *
 * Each check returns a stable machine-readable `key` plus `passed`, so the UI
 * branches on the key and the copy stays translatable.
 */
class UpdatePreflight
{
    public function __construct(private InstalledPanelInfo $installed) {}

    /**
     * @return array{
     *     ready: bool,
     *     checks: list<array{key: string, passed: bool, detail: string|null, advisory?: bool}>
     * }
     */
    public function run(): array
    {
        $checks = [
            $this->gitCheckout(),
            $this->cleanWorkingTree(),
            $this->freeDisk(),
            $this->freeMemory(),
            $this->writablePath(),
        ];

        return [
            'ready' => $this->ready($checks),
            'checks' => $checks,
        ];
    }

    /**
     * `ready` is the single thing the update button binds to.
     *
     * Advisory checks are reported but never gate it. The distinction is
     * whether the check can be *wrong* in a way the admin cannot act on: a
     * dirty working tree is a fact that stays true until someone fixes it, but
     * free memory is a sample of a number that moves on its own — a backup
     * running at the wrong moment would disable the button with no failure to
     * point at and no way to proceed. Blocking on the volatile ones turns a
     * helpful warning into a lockout.
     *
     * Missing `advisory` means blocking, so a check added later has to opt out
     * deliberately rather than by forgetting a key.
     *
     * Public and pure, so the rule is tested without a machine in a
     * particular state.
     *
     * @param  list<array{key: string, passed: bool, detail: string|null, advisory?: bool}>  $checks
     */
    public function ready(array $checks): bool
    {
        return collect($checks)
            ->reject(fn (array $check): bool => $check['advisory'] ?? false)
            ->every(fn (array $check): bool => $check['passed']);
    }

    /**
     * @return array{key: string, passed: bool, detail: string|null}
     */
    private function gitCheckout(): array
    {
        $isGit = is_dir($this->installed->repositoryPath().'/.git');

        return [
            'key' => 'git_checkout',
            'passed' => $isGit,
            'detail' => $isGit ? null : $this->installed->repositoryPath(),
        ];
    }

    /**
     * The check that will actually stop people. `git checkout --force` throws
     * away uncommitted work silently, and editing a file on your own server is
     * a normal thing to do — so an update must refuse, not overwrite.
     *
     * @return array{key: string, passed: bool, detail: string|null}
     */
    private function cleanWorkingTree(): array
    {
        $changes = $this->installed->localChanges();

        return [
            'key' => 'clean_working_tree',
            // Unknown (null) fails closed: if we cannot prove the tree is
            // clean, we do not get to destroy what might be in it.
            'passed' => $changes === [],
            'detail' => $this->changesDetail($changes),
        ];
    }

    /**
     * Say which files are in the way.
     *
     * This check is the one that actually stops people, and it used to report
     * nothing at all — a blocked update, no reason, and no way to act on it
     * short of an SSH session. Naming the paths turns it into something the
     * reader can fix.
     *
     * Capped, because the answer to "why is my update blocked" is not a
     * thousand-line diff: a stale checkout can have hundreds of entries, and
     * the point is to identify the problem, not to reproduce `git status`.
     *
     * @param  array<int, string>|null  $changes
     */
    private function changesDetail(?array $changes): ?string
    {
        if ($changes === null) {
            return 'unknown';
        }

        if ($changes === []) {
            return null;
        }

        $shown = array_slice($changes, 0, 5);
        $rest = count($changes) - count($shown);

        return sprintf(
            '%d uncommitted change%s: %s%s',
            count($changes),
            count($changes) === 1 ? '' : 's',
            implode(', ', $shown),
            $rest > 0 ? sprintf(' and %d more', $rest) : '',
        );
    }

    /**
     * @return array{key: string, passed: bool, detail: string|null}
     */
    private function freeDisk(): array
    {
        $required = (int) config('panel_update.preflight.min_free_disk_mb', 2048);
        $free = @disk_free_space($this->installed->repositoryPath());

        if ($free === false) {
            return ['key' => 'free_disk', 'passed' => false, 'detail' => 'unknown'];
        }

        $freeMb = (int) floor($free / 1048576);

        return [
            'key' => 'free_disk',
            'passed' => $freeMb >= $required,
            'detail' => $freeMb.'MB free, '.$required.'MB required',
        ];
    }

    /**
     * `npm run build` is the step that OOMs on small VPSes. Available memory
     * (not "free") is the number that matters — page cache is reclaimable.
     *
     * Swap counts. That is not a concession: it is the whole reason a 1 GB box
     * can build the panel at all, and install.sh adds a swapfile at install
     * time for exactly this step. Judging the box on MemAvailable alone would
     * refuse an update on the very servers the installer prepared to survive
     * one — a build that finishes slowly is still a build that finishes.
     *
     * Advisory, never blocking. Unlike every other check here this one samples
     * a number that moves by itself: MemAvailable dips whenever a backup, a
     * customer's own build or a burst of php-fpm children happens to be
     * running, and gating on it meant the update button could be dark for
     * reasons that had nothing to do with the update and would be gone a
     * minute later. The figure is still worth showing — it is the first thing
     * to look at when a build is killed — but it is a reading, not a verdict.
     *
     * @return array{key: string, passed: bool, detail: string|null, advisory: bool}
     */
    private function freeMemory(): array
    {
        $required = (int) config('panel_update.preflight.min_free_memory_mb', 768);
        $availableMb = $this->availableMemoryMb();

        if ($availableMb === null) {
            return ['key' => 'free_memory', 'passed' => false, 'detail' => 'unknown', 'advisory' => true];
        }

        return $this->memoryVerdict($availableMb, $this->freeSwapMb(), $required);
    }

    /**
     * Public and pure, so the arithmetic is tested without a fake `/proc`.
     *
     * @return array{key: string, passed: bool, detail: string|null, advisory: bool}
     */
    public function memoryVerdict(int $availableMb, ?int $swapMb, int $requiredMb): array
    {
        $swapMb ??= 0;

        return [
            'key' => 'free_memory',
            'passed' => $availableMb + $swapMb >= $requiredMb,
            // Broken out rather than summed into one number: an admin who is
            // 400 MB short needs to know whether the answer is "close a
            // process" or "add a swapfile", and those are different fixes.
            //
            // The shape is load-bearing: lib/admin/preflight-detail.js parses
            // this sentence to render a localized figure, and anything it
            // cannot parse falls back to raw English megabytes.
            'detail' => $availableMb.'MB available + '.$swapMb.'MB swap, '.$requiredMb.'MB required',
            'advisory' => true,
        ];
    }

    private function availableMemoryMb(): ?int
    {
        return $this->meminfoMb('MemAvailable');
    }

    /**
     * Free swap, or null when /proc/meminfo has no SwapFree line at all — a
     * kernel built without swap support. Treated as zero by the caller, since
     * "no swap" and "no swap left" constrain the build identically.
     */
    private function freeSwapMb(): ?int
    {
        return $this->meminfoMb('SwapFree');
    }

    private function meminfoMb(string $field): ?int
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $contents = (string) @file_get_contents('/proc/meminfo');

        if (preg_match('/^'.preg_quote($field, '/').':\s+(\d+) kB$/m', $contents, $matches) !== 1) {
            return null;
        }

        return (int) floor(((int) $matches[1]) / 1024);
    }

    /**
     * The panel user must be able to write the checkout it is about to move.
     * Running as root during install and unprivileged afterwards is exactly
     * how this ends up false in the wild.
     *
     * @return array{key: string, passed: bool, detail: string|null}
     */
    private function writablePath(): array
    {
        $path = $this->installed->repositoryPath();

        return [
            'key' => 'writable_path',
            'passed' => is_writable($path),
            'detail' => is_writable($path) ? null : $path,
        ];
    }

    /**
     * Whether git can be executed at all. Kept separate from the checks above
     * because a missing git binary is an install problem, not a state problem.
     */
    public function gitAvailable(): bool
    {
        return Process::timeout(10)->run(['git', '--version'])->successful();
    }
}
