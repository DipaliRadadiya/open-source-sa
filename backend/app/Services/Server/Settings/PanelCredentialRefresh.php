<?php

namespace App\Services\Server\Settings;

use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Make the panel itself use a credential it has just written to `.env`.
 *
 * Writing `.env` is not applying a change. `install.sh` runs
 * `php artisan config:cache`, so in production the old value is compiled into
 * `bootstrap/cache/config.php` and the file the panel actually reads still
 * holds it. And `queue:work` is a long-running process that loaded its
 * configuration once, at start, so it would keep presenting the old credential
 * however many times the cache were rebuilt.
 *
 * That matters most for Redis, because `install.sh` points `SESSION_DRIVER`,
 * `QUEUE_CONNECTION` and `CACHE_STORE` at it together — "one decision, not
 * three" — so on a Redis-backed install a changed password that the panel has
 * not picked up means sessions, the cache, the rate limiter and the queue all
 * start answering NOAUTH. Changing the Redis password could take the panel
 * down, which is a poor reward for using the feature.
 *
 * `ConfigureServices` has known about this for as long as it has existed and
 * says so at the end of its run — "Run `php artisan optimize` and restart
 * php-fpm and the queue worker for this to take effect." That is fair advice
 * for a command someone types during an install. It is not available to a
 * setting changed through the API, which has to do the work itself.
 *
 * php-fpm needs no reload: each request boots the framework again and reads
 * whatever the config cache now says.
 */
class PanelCredentialRefresh
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Rebuild the config cache and restart the queue worker.
     *
     * Both are attempted even if the first fails: they are independent, and a
     * queue worker that could have been restarted should be, whatever happened
     * to the cache.
     *
     * Failures are logged rather than raised. This runs after the response, so
     * there is no caller to raise to — and the credential itself has already
     * changed successfully, so an exception here would report an error about
     * something that worked. The visible consequence is that the panel is
     * still holding the old value, which the next `RedisSettings::read()`
     * reports by itself: it authenticates with what the config cache now says,
     * fails, and answers `has_password: null`.
     */
    public function apply(): void
    {
        $this->rebuildConfigCache();
        $this->restartQueueWorker();
    }

    /**
     * In-process rather than shelled out: the panel already runs as the user
     * that owns `bootstrap/cache`, so there is nothing to elevate and no
     * second PHP to start.
     *
     * `config:cache` rather than `config:clear`. Clearing would also make the
     * new value visible, and would quietly leave production uncached — a
     * performance regression nobody would ever notice to undo.
     */
    private function rebuildConfigCache(): bool
    {
        // Only when one exists. A cached config is a *copy* of what `.env`
        // says, so it has to be rebuilt when `.env` changes; an uncached panel
        // reads the file itself on the next request and there is nothing here
        // to do. `install.sh` caches, so production takes the first branch and
        // a development checkout takes the second.
        //
        // Not merely an optimisation. Writing a config cache where there was
        // none changes how the panel resolves configuration from that moment
        // on, which is a larger decision than this method is entitled to make
        // — and it is not hypothetical: doing it unconditionally created a
        // cache during the test suite, which then overrode `phpunit.xml` and
        // pointed the following tests at the real database.
        if (! app()->configurationIsCached()) {
            return true;
        }

        try {
            Artisan::call('config:cache');

            return true;
        } catch (Throwable $e) {
            $this->log('panel config cache could not be rebuilt: '.$e->getMessage());

            return false;
        }
    }

    /**
     * The worker holds its configuration from when it started, so this is the
     * only way it learns anything. Restart rather than `queue:restart`: that
     * asks the worker to stop after its current job by way of the cache — and
     * the cache is on the Redis whose credential just changed, which is the
     * one place the message cannot reliably arrive.
     */
    private function restartQueueWorker(): bool
    {
        $service = (string) config('panel_update.services.queue', 'panel-queue.service');

        if ($service === '') {
            return true;
        }

        $result = $this->serverOps->run(
            ['systemctl', 'restart', $service],
            ['feature' => 'setting', 'group' => 'redis', 'op' => 'restart_queue', 'service' => $service],
            timeout: 60,
        );

        if ($result->failed()) {
            $this->log("queue worker {$service} could not be restarted", $result);

            return false;
        }

        return true;
    }

    private function log(string $message, ?ServerOpsResult $result = null): void
    {
        Log::channel('server-ops')->error($message, [
            'reference' => $result?->reference ?? (string) Str::uuid(),
            'feature' => 'setting',
            'group' => 'redis',
        ]);
    }
}
