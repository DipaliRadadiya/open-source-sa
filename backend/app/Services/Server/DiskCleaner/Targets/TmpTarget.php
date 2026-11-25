<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\DiskCleaner\KeepDays;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Date;

/**
 * Old files in /tmp and /var/tmp: older than the keep choice, or — for delete
 * all — every file nothing has written to in the last hour.
 *
 * Delete all does not mean "everything" here: /tmp is where running work lives (installers,
 * staging and clone dumps, uploads, package builds), so an hour without a write is the line
 * between a file in use and one left behind.
 */
class TmpTarget extends AbstractCleanupTarget
{
    /** How recently a file may have been written and still be treated as in use. */
    private const IN_USE_MINUTES = 60;

    public function key(): string
    {
        return 'tmp';
    }

    public function group(): string
    {
        return 'temp';
    }

    public function method(): string
    {
        return 'delete';
    }

    public function available(): bool
    {
        return count($this->dirs()) > 0;
    }

    public function paths(): array
    {
        return array_map(fn (string $dir) => "{$dir}/*", $this->dirs());
    }

    public function estimate(?int $keepDays = null): int
    {
        $days = $keepDays ?? KeepDays::RECOMMENDED;

        return $this->estimates([$days])[$days];
    }

    public function estimates(array $choices): array
    {
        $files = $this->scan($this->findArgs());

        return collect($choices)
            ->mapWithKeys(fn (int $days) => [$days => $this->sumOlderThan($files, $this->cutoff($days))])
            ->all();
    }

    /**
     * `null` keeps the original test (`-mtime +7`, i.e. eight days or more) so an untouched
     * schedule does not start deleting a day earlier.
     */
    public function clean(?int $keepDays = null): ServerOpsResult
    {
        $age = match ($keepDays) {
            null => ['-mtime', '+'.KeepDays::RECOMMENDED],
            KeepDays::DELETE_ALL => ['-mmin', '+'.self::IN_USE_MINUTES],
            default => KeepDays::findAge($keepDays),
        };

        return $this->serverOps->run(
            [...$this->findArgs(), ...$age, '-delete'],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            120,
        );
    }

    /** The estimate's side of the same line the clean draws. */
    private function cutoff(int $days): int
    {
        return KeepDays::cutoff($days) ?? Date::now()->getTimestamp() - self::IN_USE_MINUTES * 60;
    }

    /**
     * @return array<int, string>
     */
    private function dirs(): array
    {
        return array_values(array_filter(['/tmp', '/var/tmp'], 'is_dir'));
    }

    private function olsRuntimeDir(): string
    {
        return rtrim((string) config('server.web_server_drivers.openlitespeed.tmp_dir', '/tmp/lshttpd'), '/');
    }

    /**
     * @return array<int, string>
     */
    private function findArgs(): array
    {
        // `-xdev`: anything mounted under /tmp belongs to something else.
        return [
            'find', ...$this->dirs(), '-xdev', '-type', 'f',
            // OpenLiteSpeed's runtime directory. Its pid file is never touched after start, so it
            // looks "old"; without it `lswsctrl restart` takes its "not running" path and logs
            // fatal "address already in use" errors. Nothing in there is junk while OLS runs.
            '!', '-path', $this->olsRuntimeDir().'/*',
        ];
    }
}
