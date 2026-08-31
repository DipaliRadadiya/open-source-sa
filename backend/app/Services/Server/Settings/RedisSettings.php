<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\EnvFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Redis memory + auth settings via redis-cli CONFIG SET/REWRITE. Detect-gated
 * (only surfaces when redis-cli is present).
 *
 * **The password is returned** — operator decision, 2026-08-31, so the panel
 * can show and copy it the way it already shows a system user's. The objection
 * is recorded here because it is not the same secret as a system user's, and
 * whoever reads this next should know what was traded:
 *
 * Redis backs this panel's sessions, cache and queue. Its queue payloads carry
 * database credentials, git tokens and storage keys while jobs are pending, and
 * its session store is what authenticates every logged-in admin. Every hosted
 * site runs PHP as its own user on the same machine and can open 127.0.0.1:6379
 * — so this password is the only thing between a compromised WordPress install
 * and the panel's own sessions. A system user's password unlocks one customer's
 * account; this one unlocks the panel.
 *
 * It is therefore returned only to a caller who could change it anyway
 * (`setting` **manage**, not view). A read-only role still gets `has_password`
 * and no value: nothing it can act on is hidden from it, and the credential
 * does not travel to someone who has no use for it.
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
        private PanelCredentialRefresh $refresh,
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
        $current = $this->currentPassword();

        return [
            'maxmemory' => $this->configGet('maxmemory') ?: '0',
            'maxmemory_policy' => $this->configGet('maxmemory-policy') ?: 'noeviction',
            // **Nullable.** `true` and `false` are answers; `null` means the
            // panel could not ask — its stored credential does not open a
            // connection to this Redis. Reporting `false` there claimed no
            // password on a server that requires one, which is the reading
            // most likely to make someone believe a change had been applied.
            'has_password' => match ($current) {
                null => null,
                default => $current !== '',
            },
            // The value itself. Null covers three different situations, and
            // `has_password` is what tells them apart: no password is set, the
            // panel could not ask, or the caller may not have it.
            'password' => $this->readablePassword($current),
            // Whether changing the password is even possible. If the panel
            // cannot record the new one, the control should be disabled rather
            // than offered and then refused.
            'password_manageable' => $this->env->writable(),
            ...$this->liveState(),
        ];
    }

    /**
     * The password, when the caller is one who could change it anyway.
     *
     * `setting` **manage**, not view: a read-only role can already see that a
     * password exists, and handing it the panel's own Redis credential gives
     * it nothing it can act on. See the class note for what this secret opens.
     */
    private function readablePassword(?string $current): ?string
    {
        if ($current === null || $current === '') {
            return null;
        }

        return (Auth::user()?->canManage('setting') ?? false) ? $current : null;
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

        $previous = $this->currentPassword();

        // Refuse here, for the same reason the writability check above does:
        // everything past this point runs *after* the response, where the only
        // way to report a failure is a log line nobody reads.
        //
        // A null `$previous` means the panel's own credential does not open a
        // connection to this Redis, so the `CONFIG SET` below is already known
        // to be about to fail with NOAUTH. Queueing it anyway answered 202 and
        // "the Redis password is being applied", and then changed nothing —
        // which is exactly the report this came in as.
        //
        // A 422 naming the cause is worth more than a success message that is
        // not true.
        if ($previous === null) {
            throw ValidationException::withMessages([
                'password' => [__('errors/setting.redis_credential_unusable')],
            ]);
        }

        $this->passwordChangePending = true;

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

            return;
        }

        // Writing `.env` is not applying it. `install.sh` runs
        // `config:cache`, so the old password is compiled into
        // `bootstrap/cache/config.php` and that is the file the panel reads;
        // and `queue:work` loaded its configuration once, when it started.
        //
        // The installer points sessions, the cache and the queue at Redis
        // together, so without this the panel keeps offering the old password
        // to all of them and Redis answers NOAUTH to every one — a successful
        // password change that takes the panel down.
        //
        // Last, and only on the path where the new credential has already been
        // proven to work: nothing here should run for a change that did not
        // stick.
        // Failures are logged, not returned: this runs after the response, so
        // there is no caller left to tell. They are not invisible either — the
        // next `read()` authenticates with whatever the config cache now holds,
        // so a refresh that did not happen makes `has_password` come back
        // `null`, which is precisely "the panel cannot reach Redis with the
        // credential it has". The failure reports itself through the field
        // that already exists to say so.
        $this->refresh->apply();
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
     * The password Redis currently requires, or **null when that cannot be
     * determined** — read from a connection authorised by the panel's own
     * stored credential.
     *
     * The null is the whole point. This used to return `''` for both "Redis
     * requires no password" and "the command failed", which are opposite
     * facts, and the failure is the likely one exactly when it matters: if
     * `.env`'s REDIS_PASSWORD has drifted from the running server — a password
     * set outside the panel, a restored redis.conf, an adopted server — then
     * `CONFIG GET` answers NOAUTH, the output is empty, and `''` came back
     * meaning "no password is set".
     *
     * Two things followed from that. `read()` reported `has_password: false`
     * about a Redis that very much does require one; and `setPassword()` used
     * `''` as the credential to authenticate the change, so the change was
     * rejected — after the response had already told the user it was applied.
     *
     * Same shape as the storage-destination probe's nullable
     * `last_test_success`: never-asked, asked-and-failed, and asked-and-true
     * are three states, and collapsing any two of them tells the user
     * something false.
     */
    private function currentPassword(): ?string
    {
        $result = $this->authenticated(['config', 'get', 'requirepass'], (string) config('database.redis.default.password'));

        if ($result->failed()) {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        // `CONFIG GET` answers with the name and then the value. A server with
        // no password answers with an *empty* value, and trimming the output
        // eats that trailing blank line — so one line back is the real
        // "no password" answer, not a short read.
        //
        // What tells the two apart is the name: a reply that does not begin
        // with `requirepass` is not an answer to this question at all, and
        // reading a password out of it would be a guess.
        if (($lines[0] ?? '') !== 'requirepass') {
            return null;
        }

        return trim($lines[1] ?? '');
    }

    /**
     * Whether Redis is actually up, and how much memory it is using.
     *
     * `available()` only says redis-cli is installed, so "installed but not
     * running" is a state this group can be in and should report rather than
     * render as an empty form.
     *
     * PING rather than `systemctl is-active`: a NOAUTH reply still proves a
     * server answered, and it asks the process itself instead of asking systemd
     * about a unit that may not be the one serving this socket.
     *
     * Usage is reported next to the limit because a limit on its own tells the
     * reader nothing — 512 MB is either roomy or nearly full, and only the pair
     * says which.
     *
     * @return array<string, mixed>
     */
    private function liveState(): array
    {
        $ping = $this->cli(['ping']);
        $running = $ping->ok || str_contains($ping->output().$ping->errorOutput(), 'NOAUTH');

        if (! $running) {
            return ['running' => false, 'memory_used' => null, 'memory_used_human' => null];
        }

        $info = $this->cli(['info', 'memory']);

        // used_memory is the byte count; used_memory_human is Redis's own
        // formatting of the same number, taken rather than reimplemented so the
        // panel and redis-cli never disagree by a rounding step.
        $used = null;
        $human = null;

        foreach (preg_split('/\r?\n/', trim($info->output())) ?: [] as $line) {
            if (preg_match('/^used_memory:(\d+)/', trim($line), $bytes) === 1) {
                $used = (int) $bytes[1];
            }
            if (preg_match('/^used_memory_human:(.+)$/', trim($line), $pretty) === 1) {
                $human = trim($pretty[1]);
            }
        }

        return ['running' => true, 'memory_used' => $used, 'memory_used_human' => $human];
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
