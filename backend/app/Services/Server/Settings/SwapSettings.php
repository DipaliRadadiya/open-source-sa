<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Support\Bytes;
use Illuminate\Support\Facades\File;

/**
 * Swap file management — create, resize, or disable ONE managed swap file.
 * Non-destructive: only our own file (`server.swap_file`) and its single
 * `/etc/fstab` line are ever touched, so a migrated server keeps whatever swap
 * it already had. Size is a validated integer → the ServerOps array-args carry
 * no shell-injection surface.
 */
class SwapSettings implements SettingGroup
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
    ) {}

    public function key(): string
    {
        return 'swap';
    }

    public function available(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        [$total, $free] = $this->swapTotals();
        $used = max(0, $total - $free);

        return [
            'enabled' => $total > 0,
            'path' => $this->path(),
            'size' => $total,
            'size_human' => Bytes::human($total),
            'used' => $used,
            'used_human' => Bytes::human($used),
            'free' => $free,
            'free_human' => Bytes::human($free),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $sizeMb = (int) $data['size_mb'];

        $sizeMb === 0 ? $this->disable() : $this->createOrResize($sizeMb);
    }

    private function createOrResize(int $sizeMb): void
    {
        $file = $this->path();

        // Take an existing managed swap file offline before resizing it.
        if ($this->isActive()) {
            $this->run(['swapoff', $file], allowFailure: true);
        }

        $this->run(['fallocate', '-l', "{$sizeMb}M", $file]);
        $this->run(['chmod', '600', $file]);
        $this->run(['mkswap', $file]);
        $this->run(['swapon', $file]);

        $this->ensureFstab($file);
    }

    private function disable(): void
    {
        $file = $this->path();

        if ($this->isActive()) {
            $this->run(['swapoff', $file], allowFailure: true);
        }

        $this->removeFstab($file);

        if (is_file($file)) {
            $this->files->delete($file, ['feature' => 'setting', 'group' => 'swap']);
        }
    }

    private function path(): string
    {
        return (string) config('server.swap_file', '/swapfile');
    }

    /**
     * @return array{0: int, 1: int} [total bytes, free bytes]
     */
    private function swapTotals(): array
    {
        $path = rtrim((string) config('server.proc_dir', '/proc'), '/').'/meminfo';
        $contents = is_file($path) ? (string) @file_get_contents($path) : '';

        $total = 0;
        $free = 0;
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/^SwapTotal:\s+(\d+)/', $line, $m)) {
                $total = (int) $m[1] * 1024;
            }
            if (preg_match('/^SwapFree:\s+(\d+)/', $line, $m)) {
                $free = (int) $m[1] * 1024;
            }
        }

        return [$total, $free];
    }

    private function isActive(): bool
    {
        $output = $this->serverOps->run(
            ['swapon', '--show=NAME', '--noheadings'],
            ['feature' => 'setting', 'group' => 'swap', 'op' => 'read'],
        )->output();

        return str_contains($output, $this->path());
    }

    private function ensureFstab(string $file): void
    {
        $path = (string) config('server.fstab', '/etc/fstab');
        $contents = is_file($path) ? (string) File::get($path) : '';

        if (str_contains($contents, $file.' ')) {
            return; // already mounted at boot — leave it be
        }

        $this->must($this->files->put($path, rtrim($contents, "\n")."\n{$file} none swap sw 0 0\n", ['feature' => 'setting', 'group' => 'swap']));
    }

    private function removeFstab(string $file): void
    {
        $path = (string) config('server.fstab', '/etc/fstab');
        if (! is_file($path)) {
            return;
        }

        $kept = array_filter(
            preg_split('/\r?\n/', (string) File::get($path)) ?: [],
            fn (string $line) => $line !== '' && ! str_contains($line, $file.' '),
        );

        $this->must($this->files->put($path, $kept === [] ? '' : implode("\n", $kept)."\n", ['feature' => 'setting', 'group' => 'swap']));
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command, bool $allowFailure = false): void
    {
        $result = $this->serverOps->run($command, ['feature' => 'setting', 'group' => 'swap', 'op' => 'apply']);

        if (! $allowFailure && $result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }

    private function must(ServerOpsResult $result): void
    {
        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }
}
