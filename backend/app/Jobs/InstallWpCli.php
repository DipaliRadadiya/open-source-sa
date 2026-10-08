<?php

namespace App\Jobs;

use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\ServerOps;
use App\Services\Server\WpCli\WpCli;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs wp-cli from the setup page (bug #4).
 *
 * Queued because it is a download from GitHub, and `$tries = 1` like every
 * other server install: a retry repeats a failure someone needs to read.
 * Unique so two clicks cannot write the same file at once.
 */
class InstallWpCli implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    /** wp-cli's own "latest" build is what the download is, so it is also true. */
    public const RUNTIME = 'wp_cli';

    public const VERSION = 'latest';

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public ?int $actorId = null) {}

    public function handle(
        ServerOps $serverOps,
        WpCli $wpCli,
        ActivityLogger $log,
        InstallTracker $installs,
        InstallFailureClassifier $classifier,
    ): void {
        $installs->begin(self::RUNTIME, self::VERSION);

        foreach ($wpCli->installCommands() as $command) {
            $result = $serverOps->run($command, ['feature' => 'wp_cli', 'op' => 'install'], timeout: 240);

            if ($result->failed()) {
                $installs->fail(self::RUNTIME, self::VERSION, null, $classifier->classify(self::RUNTIME, $result), $result->reference);
                $log->log('wp_cli.install_failed', null, ['reference' => $result->reference], actor: $this->actor());

                return;
            }
        }

        // Written is not the same as runnable: the phar needs `php` on PATH.
        if (! $wpCli->installed()) {
            $installs->fail(self::RUNTIME, self::VERSION, null, 'incomplete');
            $log->log('wp_cli.install_failed', null, [], actor: $this->actor());

            return;
        }

        $installs->succeed(self::RUNTIME, self::VERSION);
        $log->log('wp_cli.installed', null, [], actor: $this->actor());
    }

    /** Killed or timed out: release the row instead of spinning on it forever. */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon(self::RUNTIME, self::VERSION, why: $e);
    }
}
