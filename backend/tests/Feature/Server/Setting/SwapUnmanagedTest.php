<?php

use App\Exceptions\Server\Setting\SettingOperationException;
use App\Models\User;
use App\Services\Server\Settings\SwapSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * A server that arrived with its own swap.
 *
 * This class promises to touch "only our own file and its single /etc/fstab
 * line, so a migrated server keeps whatever swap it already had" — and that is
 * the promise these tests are about, because it was not kept. Both checks
 * searched for the managed path as a *substring*, so `/mnt/data/swapfile`
 * matched `/swapfile` and the panel treated somebody else's swap as its own.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->dir = sys_get_temp_dir().'/sv-oss-swap-'.getmypid();
    File::ensureDirectoryExists($this->dir);

    $this->swapFile = $this->dir.'/swapfile';
    $this->fstab = $this->dir.'/fstab';

    // Somebody else's swap, at a path that *contains* ours — the shape that
    // made `str_contains` wrong. On a real server this is `/mnt/data/swapfile`
    // against a managed `/swapfile`.
    $this->otherSwap = '/mnt/data'.$this->swapFile;

    config([
        'server.swap_file' => $this->swapFile,
        'server.fstab' => $this->fstab,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/**
 * @param  string  $activeSwap  what `swapon --show=NAME` reports, if anything
 */
function fakeSwapServer(string $activeSwap = ''): void
{
    Process::fake(function ($process) use ($activeSwap) {
        $command = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($command[0] ?? '') === 'swapon' && ($command[1] ?? '') === '--show=NAME') {
            return Process::result(output: $activeSwap === '' ? '' : $activeSwap."\n");
        }

        return Process::result(exitCode: 0);
    });
}

it('does not mistake another swap file for its own', function () {
    // The machine has its own swap, at a path that *ends* with ours.
    fakeSwapServer($this->otherSwap);

    $read = app(SwapSettings::class)->read();

    // The panel manages nothing here. Saying otherwise attributed someone
    // else's swap to this screen, and made "disable" look like it did nothing.
    expect($read['enabled'])->toBeFalse()
        ->and($read['unmanaged'])->toBeTrue()
        ->and($read['size'])->toBe(0);
});

it('recognises its own swap file exactly', function () {
    fakeSwapServer($this->swapFile);

    expect(app(SwapSettings::class)->read()['enabled'])->toBeTrue();
});

it('adds its fstab line even when another swap file is listed there', function () {
    File::put($this->fstab, "UUID=abc / ext4 defaults 0 1\n{$this->otherSwap} none swap sw 0 0\n");

    fakeSwapServer();

    $written = null;

    Process::fake(function ($process) use (&$written) {
        $command = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($command[0] ?? '') === 'swapon' && ($command[1] ?? '') === '--show=NAME') {
            return Process::result(output: '');
        }

        if (($command[0] ?? '') === 'tee' && ($command[1] ?? '') === $this->fstab) {
            $written = (string) $process->input;
        }

        return Process::result(exitCode: 0);
    });

    app(SwapSettings::class)->apply(['size_mb' => 512]);

    // Searching the whole file for the path found `/mnt/data/swapfile`,
    // concluded our line was already there, and wrote nothing — so swap worked
    // until the next reboot and then silently did not.
    expect($written)->toContain($this->swapFile.' none swap sw 0 0')
        ->and($written)->toContain($this->otherSwap.' none swap sw 0 0');
});

it('removes only its own fstab line', function () {
    File::put($this->fstab, "UUID=abc / ext4 defaults 0 1\n\n# a comment about {$this->swapFile}\n{$this->otherSwap} none swap sw 0 0\n{$this->swapFile} none swap sw 0 0\n");

    $written = null;

    Process::fake(function ($process) use (&$written) {
        $command = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($command[0] ?? '') === 'swapon' && ($command[1] ?? '') === '--show=NAME') {
            return Process::result(output: '');
        }

        if (($command[0] ?? '') === 'tee' && ($command[1] ?? '') === $this->fstab) {
            $written = (string) $process->input;
        }

        return Process::result(exitCode: 0);
    });

    app(SwapSettings::class)->apply(['size_mb' => 0]);

    expect($written)->not->toContain("\n{$this->swapFile} none swap")
        // Everything else survives, including the blank line and the comment
        // that merely mentions our path.
        ->and($written)->toContain('UUID=abc / ext4 defaults 0 1')
        ->and($written)->toContain($this->otherSwap.' none swap sw 0 0')
        ->and($written)->toContain('# a comment about')
        ->and($written)->toContain("\n\n");
});

it('refuses to rewrite an fstab it could not read', function () {
    File::put($this->fstab, "UUID=abc / ext4 defaults 0 1\n");
    chmod($this->fstab, 0000);

    fakeSwapServer();

    // is_file() is true for a file this process cannot read, and the writer
    // *replaces* what it read. Treating unreadable as empty would leave an
    // fstab holding one swap line and nothing else — every other mount gone.
    expect(fn () => app(SwapSettings::class)->apply(['size_mb' => 512]))
        ->toThrow(SettingOperationException::class);

    chmod($this->fstab, 0644);
})->skip(fn () => posix_geteuid() === 0, 'runs as root — every file is readable');
