<?php

namespace App\Services\Server\DiskCleaner;

use App\Contracts\CleanupTarget;
use App\Services\Server\ServerOps;

/**
 * Shared plumbing for cleanup targets: holds ServerOps and provides estimate
 * helpers (a sized and dated `find` scan, `du`, and unit parsing). Concrete targets
 * declare their key/group/method/commands.
 */
abstract class AbstractCleanupTarget implements CleanupTarget
{
    public function __construct(protected ServerOps $serverOps) {}

    public function safe(): bool
    {
        return true;
    }

    public function keepsDays(): bool
    {
        return true;
    }

    /**
     * One estimate per choice. Targets that can price every choice from a
     * single listing override this; the default simply asks each time.
     */
    public function estimates(array $choices): array
    {
        $estimates = [];

        foreach ($choices as $choice) {
            $estimates[$choice] = $this->estimate($choice);
        }

        return $estimates;
    }

    /**
     * Size and modification time of every file a `find` selects, in one run.
     *
     * @param  array<int, string>  $command  a `find` command without an action
     * @param  string  $time  `%T@` (modified) or `%C@` (changed)
     * @return array<int, array{0: int, 1: int}> [size, time] pairs
     */
    protected function scan(array $command, string $time = '%T@'): array
    {
        $output = $this->serverOps->run(
            [...$command, '-printf', "%s\t{$time}\n"],
            ['feature' => 'disk_cleaner', 'op' => 'estimate', 'target' => $this->key()],
            timeout: 60,
            // A directory that is absent on this box is an empty answer.
            expectedExitCodes: [1],
        )->output();

        $files = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            [$size, $stamp] = array_pad(explode("\t", trim($line), 2), 2, null);

            if (is_numeric($size) && is_numeric($stamp)) {
                $files[] = [(int) $size, (int) $stamp];
            }
        }

        return $files;
    }

    /**
     * Bytes in a scan that a keep choice removes: everything for delete all,
     * otherwise what is older than the cutoff.
     *
     * @param  array<int, array{0: int, 1: int}>  $files
     */
    protected function sumOlderThan(array $files, ?int $cutoff): int
    {
        return array_sum(array_map(
            fn (array $file) => $cutoff === null || $file[1] < $cutoff ? $file[0] : 0,
            $files,
        ));
    }

    protected function du(string $path): int
    {
        $output = $this->serverOps->run(
            ['du', '-sb', $path],
            ['feature' => 'disk_cleaner', 'op' => 'estimate', 'target' => $this->key()],
        )->output();

        return (int) strtok(trim($output), "\t ");
    }

    /** Parse an SI-unit size (apt output: "123", "MB"). */
    protected function siBytes(string $number, string $unit): int
    {
        $value = (float) str_replace(',', '', $number);
        $mult = ['B' => 1, 'KB' => 1_000, 'MB' => 1_000_000, 'GB' => 1_000_000_000];

        return (int) round($value * ($mult[strtoupper($unit)] ?? 1));
    }

    /** Parse a binary-unit size (journalctl output: "120.0", "M"). */
    protected function binaryBytes(string $number, string $unit): int
    {
        $value = (float) str_replace(',', '', $number);
        $mult = ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4];
        $unit = strtoupper($unit);

        return (int) round($value * ($unit === '' ? 1 : ($mult[$unit] ?? 1)));
    }
}
