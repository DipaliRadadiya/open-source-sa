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
        $managed = $this->isActive();

        return [
            // Whether *the panel's* swap file is on, not whether the machine
            // has swap. Reporting the system total against the managed path
            // meant a server with its own swap partition — the normal case on
            // a migrated box — showed that partition's size as though this
            // screen had made it, and then "disable" appeared to do nothing,
            // because only our own file was ever removed.
            'enabled' => $managed,
            'path' => $this->path(),
            'size' => $managed ? $total : 0,
            'size_human' => Bytes::human($managed ? $total : 0),
            'used' => $managed ? $used : 0,
            'used_human' => Bytes::human($managed ? $used : 0),
            'free' => $managed ? $free : 0,
            'free_human' => Bytes::human($managed ? $free : 0),
            // What the machine has in total, whoever set it up. Reported
            // separately rather than folded in, so the screen can say "this
            // server has 4 GB of swap, none of it managed here" instead of
            // implying the panel is responsible for it — or hiding it.
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

        // Before anything is written. Asked for more than the disk could
        // hold, `fallocate` filled it — 54 GB of a 60 GB request on a server
        // with 51 GB free — and the file was left there: a full root
        // filesystem, which stops the database, the logs and every site
        // writing at all (reproduced 2026-09-23).
        $this->assertRoom($file, $sizeMb);

        // Built beside the old one, WHILE THE OLD ONE IS STILL ON, and moved
        // into place.
        //
        // Resizing cannot be done in place — `fallocate` only ever allocates,
        // so asking 2 GB down to 1.5 GB succeeded and changed nothing. And the
        // old file used to be taken offline first: when building the new one
        // then failed, the server was left with no swap and a half-built file,
        // the opposite of "the replacement exists before the original stops"
        // that the firewall and cron writers follow. Building a separate file
        // needs nothing from the old one, so nothing about it has to stop yet.
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

        // Only now does the old file have to come offline.
        //
        // A failure here used to be ignored. It cannot be: `swapoff` reads
        // every swapped-out page back into RAM first, and refuses when there
        // is not enough free memory to hold them — which is exactly the state
        // a server is in when someone decides to change its swap.
        //
        // 422 with a reason of its own, not the generic "settings change
        // failed": this one is not a fault, it is the server saying it needs
        // that swap right now, and the answer is to free memory first. The
        // replacement is thrown away and the old swap stays exactly as it was.
        if ($this->isActive()) {
            $off = $this->serverOps->run(
                ['swapoff', $file],
                ['feature' => 'setting', 'group' => 'swap', 'op' => 'swapoff'],
                // Reading gigabytes back off disk is slow, and being cut off
                // partway is how a half-disabled swap happens.
                timeout: 300,
            );

            if ($off->failed()) {
                $this->discard($staging);
                abort(422, __('errors/setting.swap_in_use'));
            }
        }

        // `mv` within a directory is a rename, so there is no moment where
        // neither file is there.
        $this->run(['rm', '-f', $file]);
        $this->run(['mv', $staging, $file]);

        $this->run(['swapon', $file]);

        $this->ensureFstab($file);
    }

    /**
     * Refuse a size the disk cannot hold, with room left over.
     *
     * The new file is built while the old one still exists, so the old one's
     * space does not count as free. The margin is what the rest of the server
     * needs to go on working — a database, logs, a site's uploads — and a swap
     * file that leaves none is worse than no swap.
     *
     * When `df` cannot answer, the request goes ahead: the allocation itself is
     * still cleaned up on failure, and refusing every resize because the
     * question could not be asked would be a worse failure than the one this
     * guards against.
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

        // Same rule as resizing, and for the same reason: deleting the file
        // out from under an active swap is worse than refusing to. Turning
        // swap off entirely is the case most likely to be refused, since it
        // has to take back every page at once.
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
     * Compared line by line and exactly. `str_contains` over the whole output
     * was wrong in the one case this class exists to handle: a server that
     * arrived with its own swap. `/mnt/data/swapfile` contains `/swapfile`, so
     * the panel decided its own file was active, attributed somebody else's
     * swap to itself in `read()`, and then tried to `swapoff` a file that does
     * not exist — failing, and reporting "swap is in use", which is the
     * opposite of what happened.
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

        // Compared against the device field of each line, not searched for in
        // the whole file. Searching matched a *different* swap file whose path
        // ended the same way, and then skipped writing our line — so swap
        // worked until the next reboot and then silently did not.
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

        // Blank lines and comments are kept. The previous version dropped
        // every empty line in the file, reflowing something the user may have
        // formatted, and would have removed a commented-out entry that merely
        // mentioned the path.
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
     * `is_file()` is true for a file this process cannot read, and the callers
     * above *rewrite* what they read. Treating an unreadable fstab as an empty
     * one would replace every mount on the machine with a single swap line —
     * the same "unreadable is not empty" mistake that would have left a
     * mongod.conf with no dbPath in it.
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
            // A reference of its own: nothing shelled out, so there is no
            // ServerOps result to borrow one from, and the user still needs
            // something to quote.
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
     * Each line paired with its device field — the first whitespace-separated
     * token, which is what identifies the entry. Comments and blank lines come
     * back with a null device so callers can keep them without matching them.
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
     * Every command here must succeed.
     *
     * There was an `allowFailure` flag, used only to ignore a failing
     * `swapoff` — which is the one failure in this class that must never be
     * ignored. Both callers now check that themselves and report why, so the
     * flag has no remaining use and is gone rather than left as an invitation.
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
