<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\EnvFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Redis memory + auth settings via redis-cli CONFIG SET/REWRITE. Detect-gated
 * (only surfaces when redis-cli is present). The password is never returned —
 * only whether one is set.
 *
 * Setting the password is the delicate part, and it used to be wrong in three
 * ways.
 *
 * It changed Redis and never touched the panel's own `REDIS_PASSWORD`, so the
 * moment cache, session or queue moved to Redis, saving this form took the
 * whole panel down with NOAUTH — and the screen you would use to undo it was
 * behind the same broken connection.
 *
 * It ran `CONFIG SET requirepass` and `CONFIG REWRITE` as two separate
 * redis-cli processes. Each is its own connection, and the second one arrives
 * after auth is required, so the rewrite failed and the password was never
 * persisted to redis.conf — it vanished on the next Redis restart.
 *
 * And it passed the password as a command argument, where `ps` is world
 * readable. redis-cli offers two ways out and both are used here: `-x` to
 * take the value on stdin, and `REDISCLI_AUTH` to authenticate from the
 * environment rather than argv.
 *
 * The change itself is applied **after the response has been sent**. The
 * request that changes the credential cannot survive it: the throttle
 * middleware writes its rate-limit headers to the cache *after* the
 * controller returns but *before* the response is flushed, and by then Redis
 * wants a password this process does not have. Re-pointing the running
 * process does not help either — RedisManager captures its config when it is
 * constructed, and the middleware is already holding a resolved store. So the
 * work is deferred to a terminating callback, the client gets its 202 first,
 * and the next request boots with a matching .env and Redis. Verified the
 * hard way: an earlier version returned a 500 with a NOAUTH trace even though
 * the change had succeeded.
 */
class RedisSettings implements SettingGroup
{
    public function __construct(
        private ServerOps $serverOps,
        private EnvFile $env,
    ) {}

    /**
     * True when this request queued a credential change for after the
     * response, so the controller can answer 202 rather than pretend the
     * change has already landed.
     */
    public bool $passwordChangePending = false;

    private bool $passwordApplied = false;

    public function key(): string
    {
        return 'redis';
    }

    public function available(): bool
    {
        return is_file((string) config('server.redis_cli', '/usr/bin/redis-cli'));
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return [
            'maxmemory' => $this->configGet('maxmemory') ?: '0',
            'maxmemory_policy' => $this->configGet('maxmemory-policy') ?: 'noeviction',
            'has_password' => $this->currentPassword() !== '',
            // Whether changing the password is even possible. If the panel
            // cannot record the new one, the control should be disabled rather
            // than offered and then refused.
            'password_manageable' => $this->env->writable(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        $this->must($this->cli(['config', 'set', 'maxmemory', (string) $data['maxmemory']]));
        $this->must($this->cli(['config', 'set', 'maxmemory-policy', (string) $data['maxmemory_policy']]));

        if (! empty($data['remove_password'])) {
            $this->setPassword('');
        } elseif (array_key_exists('password', $data) && $data['password'] !== null) {
            $this->setPassword((string) $data['password']);
        }

        // Only the memory settings need persisting here; setPassword() does
        // its own rewrite while it still holds an authenticated connection.
        $this->must($this->cli(['config', 'rewrite']));
    }

    /**
     * Change (or clear) the Redis password, keeping the panel able to reach it.
     *
     * Ordered so that no step can leave the panel locked out: refuse before
     * changing anything if we could not record the result, verify the new
     * credential works before trusting it, and put the old one back if it
     * does not.
     *
     * @throws ValidationException|SettingOperationException
     */
    private function setPassword(string $password): void
    {
        // Refuse up front. Setting a password we cannot write down is exactly
        // how the panel locks itself out, so this is checked before Redis is
        // touched at all rather than after.
        if (! $this->env->writable()) {
            throw ValidationException::withMessages([
                'password' => [__('errors/setting.env_not_writable')],
            ]);
        }

        $this->passwordChangePending = true;
        $previous = $this->currentPassword();

        App::terminating(function () use ($password, $previous): void {
            $this->applyPassword($password, $previous);
        });
    }

    /**
     * Swap the credential on Redis and record it, in that order.
     *
     * Runs after the response, so nothing here can report to the caller. It
     * therefore has to leave the server in a state the panel can still reach
     * whatever happens: verify before recording, and put the old password
     * back if the new one cannot be proven to work.
     */
    private function applyPassword(string $password, string $previous): void
    {
        // Terminating callbacks can be invoked more than once — a second pass
        // would try to authenticate with a credential it has already replaced.
        if ($this->passwordApplied) {
            return;
        }

        $this->passwordApplied = true;

        // -x takes the value on stdin, so it never appears in `ps`. An empty
        // password clears auth.
        //
        // Authenticated with the password that is live *now*: replacing an
        // existing password needs the old one, and without it Redis answers
        // NOAUTH. An earlier version left this unauthenticated, so the first
        // password could be set and no later change ever applied — it failed
        // silently, which is why the log line below is not optional.
        $set = $this->serverOps->run(
            [$this->binary(), '-x', 'config', 'set', 'requirepass'],
            ['feature' => 'setting', 'group' => 'redis', 'op' => 'set_password'],
            input: $password,
            env: $this->authEnv($previous),
        );

        if ($set->failed()) {
            $this->logFailure('redis password could not be set', $set->reference);

            return;
        }

        // Persist to redis.conf. A separate process means a new connection,
        // which now needs the password — from the environment, not argv.
        $rewrite = $this->authenticated(['config', 'rewrite'], $password);

        // Prove the credential actually works before believing it, and before
        // writing it anywhere.
        $ping = $this->authenticated(['ping'], $password);

        if ($rewrite->failed() || $ping->failed() || ! str_contains(strtoupper($ping->output()), 'PONG')) {
            $this->rollback($previous, $password);
            $this->logFailure('redis password could not be verified', $ping->reference);

            return;
        }

        try {
            $this->env->set('REDIS_PASSWORD', $password === '' ? 'null' : $password);
        } catch (Throwable $e) {
            // The panel could not record it, so put Redis back rather than
            // leave behind a server the panel cannot reach.
            $this->rollback($previous, $password);
            $this->logFailure('redis password recorded nowhere: '.$e->getMessage(), (string) Str::uuid());
        }
    }

    private function logFailure(string $message, string $reference): void
    {
        Log::channel('server-ops')->error($message, [
            'reference' => $reference,
            'feature' => 'setting',
            'group' => 'redis',
        ]);
    }

    private function rollback(string $previous, string $newPassword): void
    {
        $this->serverOps->run(
            [$this->binary(), '-x', 'config', 'set', 'requirepass'],
            ['feature' => 'setting', 'group' => 'redis', 'op' => 'rollback_password'],
            input: $previous,
            // Authenticate with the password that is live right now: the set
            // succeeded, so Redis is on the new one even though the rewrite
            // or the verify did not.
            env: $this->authEnv($newPassword),
        );
    }

    /**
     * The password Redis currently requires, read from a connection that is
     * already authorised by the panel's own credential.
     */
    private function currentPassword(): string
    {
        $result = $this->authenticated(['config', 'get', 'requirepass'], (string) config('database.redis.default.password'));

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        return isset($lines[1]) ? trim($lines[1]) : '';
    }

    private function configGet(string $key): string
    {
        $result = $this->authenticated(['config', 'get', $key], (string) config('database.redis.default.password'));

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        return isset($lines[1]) ? trim($lines[1]) : '';
    }

    /**
     * @param  array<int, string>  $args
     */
    private function cli(array $args): ServerOpsResult
    {
        return $this->authenticated($args, (string) config('database.redis.default.password'));
    }

    /**
     * @param  array<int, string>  $args
     */
    private function authenticated(array $args, string $password): ServerOpsResult
    {
        return $this->serverOps->run(
            [$this->binary(), ...$args],
            ['feature' => 'setting', 'group' => 'redis', 'op' => 'redis-cli.'.($args[0] ?? 'run')],
            env: $this->authEnv($password),
        );
    }

    /**
     * @return array<string, string>
     */
    private function authEnv(string $password): array
    {
        // redis-cli's own documented alternative to `-a`, which it warns about
        // precisely because arguments are visible to every user on the box.
        return $password === '' || $password === 'null' ? [] : ['REDISCLI_AUTH' => $password];
    }

    private function binary(): string
    {
        return (string) config('server.redis_cli', '/usr/bin/redis-cli');
    }

    private function must(ServerOpsResult $result): void
    {
        if ($result->failed()) {
            throw new SettingOperationException($result->reference);
        }
    }
}
