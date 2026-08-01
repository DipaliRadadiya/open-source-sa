<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Server\Fail2ban\Fail2banManager;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Installs fail2ban. Queued because apt is far too slow to hold a request
 * open for, and `$tries = 1` for the same reason as the other server jobs:
 * an automatic retry of a package install just repeats a failure the operator
 * needs to read.
 *
 * Nothing is enabled here. A freshly installed fail2ban that immediately
 * started banning would be a surprise, and the one jail worth having is the
 * one that can lock you out — that stays a deliberate click.
 */
class InstallFail2ban implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public ?int $actorId = null) {}

    public function handle(ServerOps $serverOps, Fail2banManager $fail2ban, ActivityLogger $log): void
    {
        $result = $serverOps->run(
            ['apt-get', 'install', '-y', 'fail2ban'],
            ['feature' => 'fail2ban', 'op' => 'install'],
            timeout: 600,
        );

        if ($result->failed()) {
            $log->log('fail2ban.install_failed', null, ['reference' => $result->reference], actor: $this->actor());

            return;
        }

        // Write our drop-in with every jail off, so the installed service has
        // the panel's settings from the start and no jail nobody asked for.
        $fail2ban->write(
            (array) config('server.fail2ban.defaults'),
            [],
            array_fill_keys(array_column((array) config('server.fail2ban.jails', []), 'name'), false),
        );

        $log->log('fail2ban.installed', null, ['version' => $fail2ban->version() ?? '—'], actor: $this->actor());
    }
}
