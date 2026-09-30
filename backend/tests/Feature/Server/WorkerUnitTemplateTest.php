<?php

use Illuminate\Support\Facades\View;

/**
 * The worker's crash-loop limit has to sit where systemd reads it.
 *
 * Under [Service], StartLimitIntervalSec is "Unknown key … ignoring" (seen in
 * the journal on a real server), so the burst counted against the default
 * 10 s window, which five restarts 5 s apart never fill.
 */
function renderWorkerUnit(bool $autoRestart): string
{
    return View::make('server.units.worker', [
        'worker' => (object) ['name' => 'Queue', 'slug' => 'queue'],
        'application' => (object) ['domain' => 'shop.test', 'id' => 7],
        'user' => 'appuser', 'directory' => '/home/appuser/shop', 'projectRoot' => '/home/appuser/shop',
        'envPath' => '/home/appuser/shop/.env', 'path' => '/usr/bin:/bin',
        'exec' => '/usr/bin/php artisan queue:work', 'stopWaitSeconds' => 60,
        'autoRestart' => $autoRestart,
    ])->render();
}

it('puts the crash-loop limit in [Unit], where systemd reads it', function () {
    $unit = renderWorkerUnit(true);
    [$unitSection, $serviceSection] = explode('[Service]', $unit, 2);

    expect($unitSection)->toContain('StartLimitBurst=5')->toContain('StartLimitIntervalSec=60')
        ->and($serviceSection)->not->toContain('StartLimit')
        ->and($serviceSection)->toContain('Restart=always');
});

it('sets no crash-loop limit on a worker that does not restart', function () {
    expect(renderWorkerUnit(false))->not->toContain('StartLimit')->toContain('Restart=no');
});
