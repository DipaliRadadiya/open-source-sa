<?php

namespace App\Services\Panel;

use App\Support\Bytes;
use Illuminate\Support\Facades\Process;

/**
 * Can an in-place update run on this box right now?
 *
 * Every check is read-only and cheap, and fails *before* anything is touched: once the panel is in
 * maintenance mode with half its dependencies reinstalled, "not enough disk" is an outage.
 *
 * Each check returns a stable `key` plus `passed`, so the UI branches on the key and the copy
 * stays translatable.
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
     * Advisory checks are reported but never gate it: a dirty working tree stays true until fixed,
     * but free memory is a sample of a number that moves on its own, and blocking on it would
     * disable the button with no failure to point at.
     *
     * Missing `advisory` means blocking, so a later check must opt out deliberately.
     *
     * Public and pure, so the rule is tested without a machine in a particular state.
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
        $isGit = $this->installed->isGitCheckout();

        return [
            'key' => 'git_checkout',
            'passed' => $isGit,
            'detail' => $isGit ? null : $this->installed->repositoryPath(),
        ];
    }

    /**
     * `git checkout --force` silently throws away uncommitted work, and editing a file on your own
     * server is normal, so an update must refuse rather than overwrite.
     *
     * @return array{key: string, passed: bool, detail: string|null}
     */
    private function cleanWorkingTree(): array
    {
        $changes = $this->installed->localChanges();

        return [
            'key' => 'clean_working_tree',
            // Unknown (null) fails closed: if the tree cannot be proven clean, do not destroy what is in it.
            'passed' => $changes === [],
            'detail' => $this->changesDetail($changes),
        ];
    }

    /**
     * Say which files are in the way.
     *
     * Without naming the paths, a blocked update had no reason and no way to act on it short of
     * SSH. Capped, because a stale checkout can have hundreds of entries and the point is to
     * identify the problem, not to reproduce `git status`.
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
            return ['key' => 'free_disk', 'passed' => false, 'detail' => 'unknown', 'values' => null, 'message' => __('panel_update.preflight_detail.unknown')];
        }

        $freeMb = (int) floor($free / 1048576);

        return [
            'key' => 'free_disk',
            'passed' => $freeMb >= $required,
            'detail' => $freeMb.'MB free, '.$required.'MB required',
            // The numbers and a localized sentence; `detail` stays for the screen that parses it.
            'values' => ['free_mb' => $freeMb, 'required_mb' => $required],
            'message' => __('panel_update.preflight_detail.free_disk', [
                'free' => Bytes::human($freeMb * 1048576),
                'required' => Bytes::human($required * 1048576),
            ]),
        ];
    }

    /**
     * `npm run build` is the step that OOMs on small VPSes. Available memory (not "free") is the
     * number that matters: page cache is reclaimable.
     *
     * Swap counts: it is why a 1 GB box can build the panel at all (install.sh adds a swapfile for
     * this step), so judging on MemAvailable alone would refuse the servers the installer prepared.
     *
     * Advisory, never blocking: unlike the other checks it samples a number that moves by itself
     * (a backup or a burst of php-fpm children), so gating on it could darken the update button for
     * reasons that vanish a minute later. A reading, not a verdict.
     *
     * @return array{key: string, passed: bool, detail: string|null, advisory: bool}
     */
    private function freeMemory(): array
    {
        $required = (int) config('panel_update.preflight.min_free_memory_mb', 768);
        $availableMb = $this->availableMemoryMb();

        if ($availableMb === null) {
            return ['key' => 'free_memory', 'passed' => false, 'detail' => 'unknown', 'advisory' => true, 'values' => null, 'message' => __('panel_update.preflight_detail.unknown')];
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
            // Broken out, not summed: closing a process and adding a swapfile are different fixes.
            //
            // The shape is load-bearing: lib/admin/preflight-detail.js parses this sentence to render
            // a localized figure, and anything it cannot parse falls back to raw English megabytes.
            'detail' => $availableMb.'MB available + '.$swapMb.'MB swap, '.$requiredMb.'MB required',
            'advisory' => true,
            'values' => ['available_mb' => $availableMb, 'swap_mb' => $swapMb, 'required_mb' => $requiredMb],
            'message' => __('panel_update.preflight_detail.free_memory', [
                'available' => Bytes::human($availableMb * 1048576),
                'swap' => Bytes::human($swapMb * 1048576),
                'required' => Bytes::human($requiredMb * 1048576),
            ]),
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
     * The panel user must be able to write the checkout it is about to move (it ends up false when
     * install ran as root and the panel runs unprivileged).
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
