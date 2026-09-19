<?php

use App\Services\Server\Settings\SwapSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The floor under the Memory screen.
 *
 * `size_mb: 0` used to be a supported way to delete the swapfile install.sh
 * created for the panel's own build — and the preflight that should have
 * caught it is advisory, so the update ran anyway and was OOM-killed part-way.
 * v7 could not do this: two swap files, and the user-facing control only ever
 * touched the second one.
 *
 * Every case here is arithmetic against a faked `/proc`, because the real one
 * reports whatever this box happens to have.
 */
beforeEach(function () {
    $this->proc = storage_path('framework/testing/proc-'.getmypid());
    File::deleteDirectory($this->proc);
    File::makeDirectory($this->proc, 0755, true);

    config([
        'server.proc_dir' => $this->proc,
        'server.swap_file' => '/swapfile-panel',
        'server.swap_minimum_mb' => 1024,
        'server.swap_enforce_minimum' => true,
        'panel_update.preflight.min_free_memory_mb' => 2560,
    ]);
});

afterEach(fn () => File::deleteDirectory($this->proc));

function fakeProc(int $ramMb, int $swapTotalMb, string $swaps = ''): void
{
    File::put(test()->proc.'/meminfo', implode("\n", [
        'MemTotal:       '.($ramMb * 1024).' kB',
        'MemAvailable:   '.($ramMb * 512).' kB',
        'SwapTotal:      '.($swapTotalMb * 1024).' kB',
        'SwapFree:       '.($swapTotalMb * 1024).' kB',
    ])."\n");

    File::put(test()->proc.'/swaps', "Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n".$swaps);
}

/*
 * A 1 GB VPS is the case the floor exists for: 2560 − 1024 = 1536 MB of swap,
 * or the build that updates the panel cannot run.
 */
it('requires enough swap to cover what RAM does not', function () {
    fakeProc(ramMb: 1024, swapTotalMb: 0);

    expect(app(SwapSettings::class)->minimumMb())->toBe(1536);
});

/*
 * A large server clears the build requirement on RAM alone, and still keeps a
 * gigabyte — install.sh's own second rule, so that a spike reaches swap rather
 * than the OOM killer. Matching it here is the point: the two must not
 * disagree about the same machine.
 */
it('still keeps the installer minimum on a machine with plenty of RAM', function () {
    fakeProc(ramMb: 8192, swapTotalMb: 0);

    expect(app(SwapSettings::class)->minimumMb())->toBe(1024);
});

/*
 * The case that keeps this honest rather than dogmatic. A box with its own
 * swap partition needs nothing from the panel, and telling its owner they may
 * not switch our file off would be the panel inventing a requirement the
 * machine has already met.
 */
it('asks for nothing when the machine has its own swap', function () {
    fakeProc(ramMb: 1024, swapTotalMb: 4096, swaps: "/dev/sda2\tpartition\t4194304\t0\t-2\n");

    expect(app(SwapSettings::class)->minimumMb())->toBe(0);
});

/*
 * Our own file must not count towards the requirement it is being measured
 * against — that is install.sh's `swap_mb - ours_mb`, and getting it wrong
 * there once produced a box that replaced a gigabyte with a 1 MB file.
 */
it('does not count its own file as though somebody else provided it', function () {
    fakeProc(ramMb: 1024, swapTotalMb: 1536, swaps: "/swapfile-panel\tfile\t\t1572864\t0\t-2\n");

    expect(app(SwapSettings::class)->minimumMb())->toBe(1536);
});

// An operator running a swap partition, zram, or a host that forbids
// swapfiles is not wrong — they just have to say so. Mirrors install.sh's
// PANEL_SWAP_MB=0.
it('steps out of the way when the operator manages swap themselves', function () {
    fakeProc(ramMb: 512, swapTotalMb: 0);
    config(['server.swap_enforce_minimum' => false]);

    expect(app(SwapSettings::class)->minimumMb())->toBe(0);
});

/*
 * The installer provisions above the preflight's requirement on purpose, so a
 * fresh box has headroom instead of sitting exactly on the line. What must
 * never invert is the direction: if install.sh drops below what the preflight
 * demands, every new small server installs swap it cannot update with.
 *
 * Checked against install.sh itself rather than a copy of the number, because
 * a copy is the thing that drifts.
 */
it('installs at least as much swap as the update requires', function () {
    $installer = file_get_contents(base_path('../install.sh'));

    expect($installer)->toMatch('/^BUILD_MEMORY_MB=(\d+)$/m');
    preg_match('/^BUILD_MEMORY_MB=(\d+)$/m', $installer, $build);
    preg_match('/^MINIMUM_SWAP_MB=(\d+)$/m', $installer, $minimum);

    expect((int) $build[1])->toBeGreaterThanOrEqual(
        (int) config('panel_update.preflight.min_free_memory_mb'),
        'install.sh would provision less memory than the panel update requires',
    );

    // And the panel's own "always keep some swap" figure is the installer's.
    expect((int) $minimum[1])->toBe((int) config('server.swap_minimum_mb'));
});

/*
 * The machine must never be left without swap while a multi-gigabyte file is
 * allocated.
 *
 * The old order was swapoff → fallocate → mkswap → swapon, so a resize ran with
 * zero swap for the whole allocation — minutes on a near-full disk, on exactly
 * the box that needed swap. The comment claimed "the replacement exists before
 * the original stops", which was true of the file and not of the swap.
 *
 * Asserted on the recorded order rather than on the presence of the commands,
 * because every one of them ran before this change too.
 */
it('allocates the replacement before taking the old swap offline', function () {
    fakeProc(ramMb: 8192, swapTotalMb: 2048, swaps: "/swapfile-panel\tfile\t\t2097152\t0\t-2\n");

    // Recorded through the fake rather than asserted after the fact: the
    // question is the ORDER, and every one of these commands ran before this
    // change too. Stateful per command, not one blanket result — a fake that
    // answers everything identically cannot represent "our file is on".
    $ran = [];

    Process::fake(function ($process) use (&$ran) {
        $ran[] = $process->command[0] ?? '';

        return ($process->command[0] ?? '') === 'swapon' && ($process->command[1] ?? '') === '--show=NAME'
            ? Process::result(output: '/swapfile-panel')
            : Process::result(output: '');
    });

    app(SwapSettings::class)->apply(['size_mb' => 4096]);

    $fallocate = array_search('fallocate', $ran, true);
    $mkswap = array_search('mkswap', $ran, true);
    $swapoff = array_search('swapoff', $ran, true);

    expect($fallocate)->not->toBeFalse()
        ->and($swapoff)->not->toBeFalse()
        ->and($fallocate)->toBeLessThan($swapoff)
        ->and($mkswap)->toBeLessThan($swapoff);
});
