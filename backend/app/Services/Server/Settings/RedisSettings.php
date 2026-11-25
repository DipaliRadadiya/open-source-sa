<?php

namespace App\Services\Server\Settings;

use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Services\Server\EnvFile;
use App\Services\Server\ManagedFile;
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
 * **The password is returned**, so the panel can show and copy it. It is more sensitive than a
 * system user's: Redis backs the panel's sessions, cache and queue (queue payloads carry database
 * credentials, git tokens and storage keys), and every hosted site's PHP user can reach
 * 127.0.0.1:6379. So it is returned only to a caller who could change it anyway (`setting`
 * **manage**, not view); a read-only role gets `has_password` and no value.
 *
 * Setting the password must:
 * - update the panel's own `REDIS_PASSWORD`, or the panel takes itself down with NOAUTH;
 * - run `CONFIG SET requirepass` and `CONFIG REWRITE` over one authenticated connection each, or
 *   the password is never persisted to redis.conf;
 * - never pass the password as an argument (`ps` is world readable): `-x` takes it on stdin and
 *   `REDISCLI_AUTH` authenticates from the environment.
 *
 * The change is applied **after the response is sent**. The throttle middleware writes its
 * rate-limit headers to the cache after the controller returns, by which time Redis wants a
 * password this process does not have, and the running process cannot be re-pointed. So the work
 * is deferred to a terminating callback and the client gets its 202 first.
 */
class RedisSettings implements SettingGroup
{
    /** The error replies redis-cli prints, and still exits 0 for. */
    private const ERROR_REPLY = '/^(?:ERR|NOAUTH|WRONGPASS|NOPERM|WRONGTYPE|READONLY|MISCONF|LOADING|BUSY|OOM|EXECABORT|NOSCRIPT|NOREPLICAS|CROSSSLOT)\b/';

    public function __construct(
        private ServerOps $serverOps,
        private EnvFile $env,
        private PanelCredentialRefresh $refresh,
        private ManagedFile $files,
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
        // Read from the panel's own .env, not `CONFIG GET`: asking Redis needs this very password,
        // and `CONFIG GET` can be renamed or disabled on a hardened Redis. Whether the value is
        // still true is `password_out_of_sync` below.
        $stored = (string) config('database.redis.default.password');

        return [
            'maxmemory' => $this->configGet('maxmemory') ?: '0',
            'maxmemory_policy' => $this->configGet('maxmemory-policy') ?: 'noeviction',
            // Policies apply() will refuse, so the form can disable them
            // rather than offer them and answer 422.
            'unsafe_policies' => self::unsafePolicies(),
            'has_password' => $stored !== '',
            // The value itself, when the caller could change it anyway.
            'password' => $this->readablePassword($stored === '' ? null : $stored),
            // False when the panel cannot record a new password, so the control is disabled.
            'password_manageable' => $this->env->writable(),
            ...$this->liveState(),
        ];
    }

    /**
     * Eviction policies that could delete the panel's own pending jobs.
     *
     * A queued job is a list entry with no TTL: `volatile-*` never touches it, but `allkeys-*`
     * evicts from every key once `maxmemory` is reached, so a pending job silently vanishes.
     * `noeviction` fails the write loudly instead.
     *
     * @return list<string>
     */
    public static function unsafePolicies(): array
    {
        $connection = (string) config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'redis') {
            return [];
        }

        return array_values(array_filter(
            (array) config('server.redis_maxmemory_policies', []),
            fn (string $policy) => str_starts_with($policy, 'allkeys-'),
        ));
    }

    /**
     * Move the eviction policy install.sh used to write to one the panel
     * accepts: its own `maxmemory-policy allkeys-lru`, directly under its
     * marker, becomes volatile-lru in redis.conf and then live
     * ({@see unsafePolicies()}).
     *
     * Only that exact line, and only while it is the policy Redis reads (the
     * last one in the file). A policy the operator set is left alone.
     *
     * @return 'changed'|'unchanged'|'unavailable'
     *
     * @throws SettingOperationException when the file or Redis refused the change
     */
    public function repairInstallerPolicy(): string
    {
        if (! $this->available()) {
            return 'unavailable';
        }

        $path = (string) config('server.redis_conf', '/etc/redis/redis.conf');
        $context = ['feature' => 'setting', 'group' => 'redis', 'op' => 'repair_installer_policy'];
        $read = $this->files->get($path, $context);

        if ($read->failed()) {
            return 'unavailable';
        }

        $contents = $read->output();
        $installer = '/^# Added by the Control panel installer\R(?:maxmemory [^\r\n]*\R)?\K(maxmemory-policy[ \t]+allkeys-lru)[ \t]*$/m';

        if (preg_match($installer, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return 'unchanged';
        }

        preg_match_all('/^[ \t]*maxmemory-policy[ \t]/m', $contents, $all, PREG_OFFSET_CAPTURE);

        if (end($all[0])[1] !== $match[1][1]) {
            return 'unchanged';
        }

        $repaired = substr_replace($contents, 'maxmemory-policy volatile-lru', $match[1][1], strlen($match[1][0]));

        // The file first: if Redis then refuses the live change, the next
        // restart still comes up on the new policy.
        $written = $this->files->put($path, $repaired, $context);

        if ($written->failed()) {
            throw new SettingOperationException($written->reference);
        }

        $this->must($this->cli(['config', 'set', 'maxmemory-policy', 'volatile-lru']));

        return 'changed';
    }

    /**
     * The password, when the caller is one who could change it anyway
     * (`setting` **manage**, not view). See the class note for what it opens.
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
        // Refuse up front: a password we cannot write down locks the panel out.
        if (! $this->env->writable()) {
            throw ValidationException::withMessages([
                'password' => [__('errors/setting.env_not_writable')],
            ]);
        }

        $previous = $this->currentPassword();

        // Everything past this point runs after the response, where a failure can only be logged.
        // A null `$previous` means the panel's credential does not open Redis, so the `CONFIG SET`
        // is already known to fail with NOAUTH: answer 422 now rather than a false 202.
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

        // -x takes the value on stdin, so it never appears in `ps`. An empty password clears auth.
        // Authenticated with the password that is live now: replacing one needs the old one.
        $set = $this->serverOps->run(
            [$this->binary(), '-x', 'config', 'set', 'requirepass'],
            ['feature' => 'setting', 'group' => 'redis', 'op' => 'set_password'],
            input: $password,
            env: $this->authEnv($previous),
        );

        if ($this->refused($set)) {
            $this->logFailure('redis password could not be set', $set->reference);

            return;
        }

        // Persist to redis.conf. A separate process means a new connection,
        // which now needs the password — from the environment, not argv.
        $rewrite = $this->authenticated(['config', 'rewrite'], $password);

        // Prove the credential actually works before believing it, and before
        // writing it anywhere.
        $ping = $this->authenticated(['ping'], $password);

        if ($this->refused($rewrite) || $ping->failed() || ! str_contains(strtoupper($ping->output()), 'PONG')) {
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

        // Writing `.env` is not applying it: the old password is compiled into the config cache
        // and the queue worker loaded its config at start. Without this refresh, sessions, cache
        // and queue all get NOAUTH. Last, and only once the new credential is proven.
        // Failures are logged, not returned: no caller is left. A missed refresh shows up as
        // `has_password` null on the next `read()`.
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
     * Null is distinct from `''` ("Redis requires no password"): when `.env`'s REDIS_PASSWORD has
     * drifted from the running server, `CONFIG GET` answers NOAUTH with empty output, and mistaking
     * that for "no password" misreports `has_password` and breaks the change's authentication.
     */
    private function currentPassword(): ?string
    {
        $result = $this->authenticated(['config', 'get', 'requirepass'], (string) config('database.redis.default.password'));

        // A rejected credential answers `NOAUTH …` with exit 0.
        if ($this->refused($result)) {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        // `CONFIG GET` answers with the name, then the value. A server with no password answers an
        // empty value that trimming eats, so one line back is a real "no password". A reply that
        // does not begin with `requirepass` is not an answer at all.
        if (($lines[0] ?? '') !== 'requirepass') {
            return null;
        }

        return trim($lines[1] ?? '');
    }

    /**
     * Whether Redis is actually up, and how much memory it is using.
     *
     * `available()` only says redis-cli is installed. PING rather than `systemctl is-active`: a
     * NOAUTH reply still proves a server answered. Usage is reported next to the limit because
     * the limit alone says nothing.
     *
     * @return array<string, mixed>
     */
    private function liveState(): array
    {
        $ping = $this->cli(['ping']);
        // Redis answered *something*, so it is up: either it accepted the
        // panel's credential, or it rejected it — and a rejection is still a
        // reply. Only silence means "not running".
        $reachable = $ping->output().$ping->errorOutput();
        $running = $ping->ok || str_contains($reachable, 'NOAUTH') || str_contains($reachable, 'WRONGPASS')
            || str_contains($reachable, 'without any password');

        if (! $running) {
            // Nothing to compare against, so the credential is unverified
            // rather than wrong. Null, not false.
            return [
                'running' => false,
                'password_out_of_sync' => null,
                'memory_used' => null,
                'memory_used_human' => null,
            ];
        }

        // Redis is up but the stored credential did not open it: someone changed `requirepass`
        // outside the panel. Not `! $ping->ok`: redis-cli exits 0 on an error reply. In sync
        // means PONG.
        $outOfSync = $this->refused($ping) || ! str_contains(strtoupper($ping->output()), 'PONG');

        $info = $this->cli(['info', 'memory']);

        // used_memory is the byte count; used_memory_human is Redis's own formatting of it, taken
        // rather than reimplemented so the panel and redis-cli never disagree.
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

        return [
            'running' => true,
            'password_out_of_sync' => $outOfSync,
            'memory_used' => $used,
            'memory_used_human' => $human,
        ];
    }

    private function configGet(string $key): string
    {
        $result = $this->authenticated(['config', 'get', $key], (string) config('database.redis.default.password'));

        if ($this->refused($result)) {
            return '';
        }

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
        // `REDISCLI_AUTH` instead of `-a`: arguments are visible to every user on the box.
        return $password === '' || $password === 'null' ? [] : ['REDISCLI_AUTH' => $password];
    }

    private function binary(): string
    {
        return (string) config('server.redis_cli', '/usr/bin/redis-cli');
    }

    private function must(ServerOpsResult $result): void
    {
        if ($this->refused($result)) {
            throw new SettingOperationException($result->reference);
        }
    }

    /**
     * Did the command fail, or did Redis refuse it?
     *
     * redis-cli exits 0 when Redis answers with an error (e.g. `NOAUTH Authentication required.`),
     * so `failed()` alone takes a rejected CONFIG SET for a saved setting. An error reply is one
     * upper-case code word at the start of the answer.
     */
    private function refused(ServerOpsResult $result): bool
    {
        if ($result->failed()) {
            return true;
        }

        return preg_match(self::ERROR_REPLY, ltrim($result->output())) === 1;
    }
}
