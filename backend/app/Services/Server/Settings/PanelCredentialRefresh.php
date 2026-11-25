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
 * Writing `.env` does not apply a change: production compiles the old value into
 * `bootstrap/cache/config.php`, and the long-running `queue:work` loaded its configuration once.
 *
 * It matters most for Redis, which backs sessions, cache, rate limiter and queue together: a
 * password change the panel has not picked up makes all of them answer NOAUTH.
 *
 * php-fpm needs no reload: each request reads the current config cache.
 */
class PanelCredentialRefresh
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Rebuild the config cache and restart the queue worker.
     *
     * Both are attempted even if the first fails: they are independent.
     *
     * Failures are logged, not raised: this runs after the response, and the credential change
     * itself succeeded. A stale value shows up in the next `RedisSettings::read()`
     * (`has_password: null`).
     */
    public function apply(): void
    {
        $this->rebuildConfigCache();
        $this->restartQueueWorker();
    }

    /**
     * In-process: the panel already runs as the user that owns `bootstrap/cache`.
     *
     * `config:cache`, not `config:clear`, which would leave production uncached.
     */
    private function rebuildConfigCache(): bool
    {
        // Only when a cache exists. Never create one unconditionally: it would override
        // `phpunit.xml` and point tests at the real database.
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
     * The worker holds its configuration from start-up, so it must be restarted. Not
     * `queue:restart`: that signals through the cache, which is the Redis whose credential
     * just changed.
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
