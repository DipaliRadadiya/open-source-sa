<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Support\Bytes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Swap file management: create, resize, or disable ONE managed swap file.
 * Only our own file (`server.swap_file`) and its `/etc/fstab` line are touched, so a migrated
 * server keeps its existing swap. Size is a validated integer, so there is no shell injection.
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
        $managed = $this->isActive();

        return [
            // Whether *the panel's* swap file is on, not whether the machine has swap; otherwise
            // a server's own swap partition would show as managed and "disable" would do nothing.
            'enabled' => $managed,
            'path' => $this->path(),
            'size' => $managed ? $total : 0,
            'size_human' => Bytes::human($managed ? $total : 0),
            'used' => $managed ? $used : 0,
            'used_human' => Bytes::human($managed ? $used : 0),
            'free' => $managed ? $free : 0,
            'free_human' => Bytes::human($managed ? $free : 0),
            // The machine's total swap, whoever set it up; kept separate so the screen can say
            // none of it is managed here.
            'system_total' => $total,
            'system_total_human' => Bytes::human($total),
            'unmanaged' => $total > 0 && ! $managed,
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
        $staging = $file.'.new';

        // Before anything is written: an oversized `fallocate` can fill the root filesystem.
        $this->assertRoom($file, $sizeMb);

        // Built beside the old file WHILE IT IS STILL ON, then moved into place: `fallocate`
        // cannot shrink in place, and a failed build must not leave the server with no swap.
        try {
            $this->run(['rm', '-f', $staging]);
            $this->run(['fallocate', '-l', "{$sizeMb}M", $staging]);
            $this->run(['chmod', '600', $staging]);
            $this->run(['mkswap', $staging]);
        } catch (SettingOperationException $e) {
            // A failed fallocate can still leave a partly allocated file.
            $this->discard($staging);

            throw $e;
        }

        // Only now does the old file come offline. Never ignore a `swapoff` failure: it reads
        // every page back into RAM and refuses when memory is short. That is a 422 (free
        // memory first), not a fault; the replacement is discarded and the old swap kept.
        if ($this->isActive()) {
            $off = $this->serverOps->run(
                ['swapoff', $file],
                ['feature' => 'setting', 'group' => 'swap', 'op' => 'swapoff'],
                // Slow; being cut off partway leaves a half-disabled swap.
                timeout: 300,
            );

            if ($off->failed()) {
                $this->discard($staging);
                abort(422, __('errors/setting.swap_in_use'));
            }
        }

        $this->run(['rm', '-f', $file]);
        $this->run(['mv', $staging, $file]);

        $this->run(['swapon', $file]);

        $this->ensureFstab($file);
    }

    /**
     * Refuse a size the disk cannot hold, with room left over.
     *
     * The old file still exists while the new one is built, so its space does not count as
     * free. The reserve keeps room for the database, logs and uploads.
     *
     * When `df` cannot answer, the request goes ahead (the allocation is cleaned up on failure).
     */
    private function assertRoom(string $file, int $sizeMb): void
    {
        $result = $this->serverOps->run(
            ['df', '-B1', '--output=avail', dirname($file)],
            ['feature' => 'setting', 'group' => 'swap', 'op' => 'disk_free'],
        );

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];
        $available = trim((string) end($lines));

        if ($result->failed() || ! ctype_digit($available)) {
            return;
        }

        $reserveMb = (int) config('server.swap_reserve_mb', 1024);
        $needed = ($sizeMb + $reserveMb) * 1024 * 1024;

        if ((int) $available < $needed) {
            throw ValidationException::withMessages([
                'size_mb' => [__('errors/setting.swap_no_space', [
                    'size' => Bytes::human($sizeMb * 1024 * 1024),
                    'available' => Bytes::human((int) $available),
                    'reserve' => Bytes::human($reserveMb * 1024 * 1024),
                ])],
            ]);
        }
    }

    /** Best effort: the caller is already failing, and says why. */
    private function discard(string $staging): void
    {
        $this->serverOps->run(
            ['rm', '-f', $staging],
            ['feature' => 'setting', 'group' => 'swap', 'op' => 'discard_staging'],
        );
    }

    private function disable(): void
    {
        $file = $this->path();

        // As when resizing: never delete the file out from under an active swap.
        if ($this->isActive()) {
            $off = $this->serverOps->run(
                ['swapoff', $file],
                ['feature' => 'setting', 'group' => 'swap', 'op' => 'swapoff'],
                timeout: 300,
            );

            abort_if($off->failed(), 422, __('errors/setting.swap_in_use'));
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

    /**
     * Is *our* swap file the one the kernel is using?
     *
     * Compared line by line and exactly: `/mnt/data/swapfile` contains `/swapfile`, so a
     * substring match would claim another swap file as ours.
     */
    private function isActive(): bool
    {
        $output = $this->serverOps->run(
            ['swapon', '--show=NAME', '--noheadings'],
            ['feature' => 'setting', 'group' => 'swap', 'op' => 'read'],
        )->output();

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (trim($line) === $this->path()) {
                return true;
            }
        }

        return false;
    }

    private function ensureFstab(string $file): void
    {
        $path = (string) config('server.fstab', '/etc/fstab');
        $contents = $this->readFstab($path);

        // Match the device field, not the whole file: a different swap file with a similar path
        // would skip our line and swap would vanish at the next reboot.
        foreach ($this->fstabLines($contents) as [$line, $device]) {
            if ($device === $file) {
                return; // already mounted at boot — leave it be
            }
        }

        $this->must($this->files->put($path, rtrim($contents, "\n")."\n{$file} none swap sw 0 0\n", ['feature' => 'setting', 'group' => 'swap']));
    }

    private function removeFstab(string $file): void
    {
        $path = (string) config('server.fstab', '/etc/fstab');

        if (! is_file($path)) {
            return;
        }

        $contents = $this->readFstab($path);

        $kept = [];

        // Blank lines and comments are kept, so the user's formatting survives.
        foreach ($this->fstabLines($contents) as [$line, $device]) {
            if ($device !== $file) {
                $kept[] = $line;
            }
        }

        $this->must($this->files->put($path, $kept === [] ? '' : implode("\n", $kept)."\n", ['feature' => 'setting', 'group' => 'swap']));
    }

    /**
     * Read /etc/fstab, distinguishing "not there" from "could not read it".
     *
     * Callers rewrite what they read, so an unreadable fstab must not be treated as empty: that
     * would replace every mount on the machine with a single swap line.
     *
     * @throws SettingOperationException
     */
    private function readFstab(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            // Nothing shelled out, so mint a reference for the user to quote.
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->error('could not read fstab', [
                'feature' => 'setting',
                'group' => 'swap',
                'op' => 'read_fstab',
                'path' => $path,
                'reference' => $reference,
            ]);

            throw new SettingOperationException($reference);
        }

        return $contents;
    }

    /**
     * Each line paired with its device field (first token); comments and blanks get null.
     *
     * @return array<int, array{0: string, 1: ?string}>
     */
    private function fstabLines(string $contents): array
    {
        $lines = [];

        foreach (preg_split('/\r?\n/', rtrim($contents, "\n")) ?: [] as $line) {
            $trimmed = trim($line);

            $device = ($trimmed === '' || str_starts_with($trimmed, '#'))
                ? null
                : (preg_split('/\s+/', $trimmed)[0] ?? null);

            $lines[] = [$line, $device];
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $command
     */
    /**
     * Every command here must succeed. `swapoff` is checked by its callers, never ignored.
     */
    private function run(array $command): void
    {
        $result = $this->serverOps->run($command, ['feature' => 'setting', 'group' => 'swap', 'op' => 'apply']);

        if ($result->failed()) {
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
