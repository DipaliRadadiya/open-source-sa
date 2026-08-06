<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Point the queue, sessions and cache at Redis when Redis is actually there.
 *
 * The panel's database is SQLite by default, and SQLite allows exactly one
 * writer. Running the queue and the session store on it means the worker is
 * polling the same file every request is trying to write to, which is how a
 * single-user panel produces "database is locked" on an idle box.
 *
 * Why a command rather than bash in the installer: the installer writes `.env`
 * once and an update never rewrites it, so encoding this in bash would fix new
 * installs and leave every existing one broken. And only the application can
 * test Redis with the credentials the application will actually use. Same
 * argument as `panel:backup-database`.
 *
 * Why not decided in `config/*.php` at runtime: `php artisan optimize` freezes
 * the config. A probe there would run once, at cache-build time, and a Redis
 * blip in that second would silently pin the panel to the wrong driver with
 * nothing to show why.
 *
 * Three rules keep this safe on somebody else's server:
 *
 *  1. **Prove, never assume.** Redis has to answer PING with the configured
 *     credential before anything is pointed at it. A failed probe changes
 *     nothing at all.
 *  2. **Never overwrite a deliberate choice.** Only a value still at the
 *     shipped default is touched. If the operator set something else, they
 *     meant it — this is a self-hosted panel and their config is theirs.
 *  3. **Never strand queued work.** `queue:work` reads the default connection,
 *     so moving it while the database queue still holds jobs orphans them
 *     silently: no error, they simply never run. It refuses and says so.
 */
class ConfigureServices extends Command
{
    protected $signature = 'panel:configure-services {--dry-run : Report what would change without writing}';

    protected $description = 'Point the queue, sessions and cache at Redis when it is available';

    /** The values this panel ships with, and the only ones safe to replace. */
    private const SHIPPED_DEFAULTS = [
        'QUEUE_CONNECTION' => 'database',
        'SESSION_DRIVER' => 'database',
        'CACHE_STORE' => 'database',
    ];

    public function handle(): int
    {
        $path = base_path('.env');

        if (! is_file($path) || ! is_readable($path)) {
            $this->warn('No readable .env — nothing to configure.');

            return self::SUCCESS;
        }

        if (! $this->redisAnswers()) {
            // Not a failure: a panel running everything on the database is
            // slower under load, not broken, and saying so loudly on every
            // update would train people to ignore the output.
            $this->line('Redis did not answer — leaving the current drivers alone.');

            return self::SUCCESS;
        }

        $contents = (string) file_get_contents($path);
        $changes = [];

        foreach (self::SHIPPED_DEFAULTS as $key => $default) {
            $current = $this->read($contents, $key);

            if ($current !== $default) {
                continue;
            }

            if ($key === 'QUEUE_CONNECTION' && ($pending = $this->pendingJobs()) > 0) {
                $this->warn("Leaving QUEUE_CONNECTION on the database: {$pending} job(s) are still queued there.");
                $this->line('Run this again once the worker has drained them, or they would never run.');

                continue;
            }

            $contents = $this->write($contents, $key, 'redis');
            $changes[$key] = 'redis';
        }

        if ($changes === []) {
            $this->info('Already configured — nothing to change.');

            return self::SUCCESS;
        }

        foreach ($changes as $key => $value) {
            $this->line(($this->option('dry-run') ? 'Would set ' : 'Set ')."{$key}={$value}");
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        file_put_contents($path, $contents);

        // The config cache is built from .env, so it is stale the moment this
        // writes. The update script rebuilds it in the step after this one;
        // anyone running this by hand needs to know.
        $this->info('Run `php artisan optimize` and restart php-fpm and the queue worker for this to take effect.');

        if (array_key_exists('SESSION_DRIVER', $changes)) {
            $this->warn('Sessions moved store — everyone signed in right now will be signed out once.');
        }

        return self::SUCCESS;
    }

    /**
     * Whether Redis answers with the credential this application is configured
     * to use — not whether a server is listening on the port.
     */
    private function redisAnswers(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Jobs still sitting on the database queue. Counted defensively: on an
     * install that never used it the table may be absent, and that is not a
     * reason to refuse.
     */
    private function pendingJobs(): int
    {
        try {
            return DB::table('jobs')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function read(string $contents, string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return trim(trim($matches[1]), '"\'');
    }

    /**
     * Replaces the value in place, leaving the rest of the file — comments,
     * ordering, the operator's own additions — exactly as it was.
     */
    private function write(string $contents, string $key, string $value): string
    {
        return (string) preg_replace(
            '/^'.preg_quote($key, '/').'=.*$/m',
            $key.'='.$value,
            $contents,
            1,
        );
    }
}
