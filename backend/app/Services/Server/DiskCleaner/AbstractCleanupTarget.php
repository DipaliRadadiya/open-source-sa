<?php

namespace App\Services\Server\DiskCleaner;

use App\Contracts\CleanupTarget;
use App\Services\Server\ServerOps;

/**
 * Shared plumbing for cleanup targets: holds ServerOps and provides estimate
 * helpers (sum `find -printf` sizes, `du`, and unit parsing). Concrete targets
 * declare their key/group/method/commands.
 */
abstract class AbstractCleanupTarget implements CleanupTarget
{
    public function __construct(protected ServerOps $serverOps) {}

    public function safe(): bool
    {
        return true;
    }

    /**
     * Sum the byte sizes emitted by a `find … -printf '%s\n'` command.
     *
     * @param  array<int, string>  $command
     */
    protected function sumSizes(array $command): int
    {
        $output = $this->serverOps->run(
            $command,
            ['feature' => 'disk_cleaner', 'op' => 'estimate', 'target' => $this->key()],
        )->output();

        $sum = 0;
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if (is_numeric($line)) {
                $sum += (int) $line;
            }
        }

        return $sum;
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
