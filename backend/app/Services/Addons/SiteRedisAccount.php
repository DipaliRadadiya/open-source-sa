<?php

namespace App\Services\Addons;

use App\Exceptions\Addons\AddonException;
use App\Models\Application;
use App\Models\ApplicationRedisAccount;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * A Redis login of the site's own, for Object Cache Pro.
 *
 * v7 opened Redis's unix socket to every account on the server
 * (`unixsocketperm 777`) and gave each site the server's Redis password, so
 * any site could read and write every other site's cache. On v8 that would
 * also be the panel's own data: the panel keeps its cache, queue and sessions
 * in the same Redis.
 *
 * Instead each site gets a Redis 6 ACL user that can touch only keys under its
 * own prefix (`~sv<id>:*`), with no pub/sub channels and no administrative or
 * dangerous commands (CONFIG, FLUSHALL, KEYS, DEBUG...). INFO is allowed back
 * because Object Cache Pro reads it; it reports the server, not anybody's
 * keys. SCAN stays allowed because Object Cache Pro flushes its own prefix
 * with it: it can list other key *names*, never their values.
 *
 * The password goes to Redis only as its SHA-256 (`#<hash>`), so it is never
 * on a command line; the panel keeps it encrypted to hand to the site.
 */
class SiteRedisAccount
{
    public function __construct(private ServerOps $serverOps) {}

    public function username(Application $application): string
    {
        return 'sv_site_'.$application->id;
    }

    public function prefix(Application $application): string
    {
        return 'sv'.$application->id.':';
    }

    /**
     * Create (or re-assert) the site's account, with a new password when
     * $rotate or none exists yet.
     *
     * @throws AddonException when this server's Redis cannot isolate sites
     */
    public function ensure(Application $application, bool $rotate = false): ApplicationRedisAccount
    {
        $this->assertCanIsolate();

        $account = ApplicationRedisAccount::firstOrNew(['application_id' => $application->id]);
        $account->username = $this->username($application);
        $account->prefix = $this->prefix($application);

        if ($rotate || blank($account->password)) {
            $account->password = Str::random(48);
        }

        $this->must($this->cli([
            'ACL', 'SETUSER', $account->username,
            'reset', 'on', '#'.hash('sha256', $account->password),
            '~'.$account->prefix.'*', 'resetchannels',
            '+@all', '-@admin', '-@dangerous', '+info',
        ], 'acl_setuser'));
        $this->persist();

        $account->save();

        return $account;
    }

    /**
     * Give the account a new password without the site ever being locked
     * out: Redis users can hold several passwords, so the new one is added,
     * $apply writes it into the site, and only then is the old one removed.
     * If $apply throws, the new password is withdrawn and the old one stays.
     *
     * @param  callable(ApplicationRedisAccount): mixed  $apply
     */
    public function rotate(ApplicationRedisAccount $account, callable $apply): mixed
    {
        $this->assertCanIsolate();

        $old = hash('sha256', $account->password);
        $new = Str::random(48);
        $newHash = hash('sha256', $new);

        $this->must($this->cli(['ACL', 'SETUSER', $account->username, '#'.$newHash], 'acl_add_password'));

        $previous = $account->password;
        $account->password = $new;

        try {
            $result = $apply($account);
        } catch (\Throwable $e) {
            $account->password = $previous;
            $this->cli(['ACL', 'SETUSER', $account->username, '!'.$newHash], 'acl_withdraw_password');
            $this->persist();

            throw $e;
        }

        $this->must($this->cli(['ACL', 'SETUSER', $account->username, '!'.$old], 'acl_remove_password'));
        $this->persist();
        $account->save();

        return $result;
    }

    /** Delete the account; a site without one is already done. */
    public function remove(Application $application): void
    {
        $account = ApplicationRedisAccount::where('application_id', $application->id)->first();

        if ($account === null) {
            return;
        }

        $result = $this->cli(['ACL', 'DELUSER', $account->username], 'acl_deluser');
        if (! $result->failed()) {
            $this->persist();
        }

        $account->delete();
    }

    /**
     * What the WordPress side needs to connect, for the toolkit's stdin.
     *
     * @return array<string, mixed>
     */
    public function connection(ApplicationRedisAccount $account): array
    {
        return [
            'host' => (string) config('database.redis.default.host', '127.0.0.1'),
            'port' => (int) config('database.redis.default.port', 6379),
            'username' => $account->username,
            'password' => $account->password,
            'database' => 0,
            'prefix' => $account->prefix,
        ];
    }

    /**
     * Isolation needs ACLs (Redis 6) and a default user that is not open --
     * a site could otherwise skip its own account and log in as `default`,
     * which can read everything.
     */
    private function assertCanIsolate(): void
    {
        $info = $this->cli(['INFO', 'server'], 'info');

        if ($info->failed() || ! preg_match('/redis_version:(\d+)\./', $info->output(), $m)) {
            throw new AddonException('object_cache_redis_unavailable', __('errors/addons.redis_unavailable'));
        }
        if ((int) $m[1] < 6) {
            throw new AddonException('object_cache_redis_unavailable', __('errors/addons.redis_too_old', ['version' => $m[1]]));
        }
        if (blank($this->adminPassword())) {
            throw new AddonException('object_cache_redis_unavailable', __('errors/addons.redis_no_password'));
        }
    }

    /**
     * Keep the account across a Redis restart: ACL SAVE when Redis keeps its
     * users in an aclfile, otherwise CONFIG REWRITE, which writes them into
     * redis.conf.
     */
    private function persist(): void
    {
        $aclfile = $this->cli(['CONFIG', 'GET', 'aclfile'], 'config_get');
        $lines = array_values(array_filter(array_map('trim', explode("\n", $aclfile->output()))));
        $usesFile = ($lines[1] ?? '') !== '';

        $this->must($this->cli($usesFile ? ['ACL', 'SAVE'] : ['CONFIG', 'REWRITE'], 'persist'));
    }

    private function cli(array $args, string $op): ServerOpsResult
    {
        $password = $this->adminPassword();

        return $this->serverOps->run(
            [(string) config('server.redis_cli', '/usr/bin/redis-cli'),
                '-h', (string) config('database.redis.default.host', '127.0.0.1'),
                '-p', (string) config('database.redis.default.port', 6379),
                ...$args],
            ['feature' => 'addon', 'addon' => 'object-cache-pro', 'op' => $op],
            // REDISCLI_AUTH, not -a: arguments are visible to every account.
            env: blank($password) ? [] : ['REDISCLI_AUTH' => $password],
        );
    }

    private function adminPassword(): ?string
    {
        $password = config('database.redis.default.password');

        return blank($password) || $password === 'null' ? null : (string) $password;
    }

    private function must(ServerOpsResult $result): void
    {
        // redis-cli exits 0 on a Redis error reply; the reply says ERR.
        if ($result->failed() || str_starts_with(ltrim($result->output()), 'ERR') || str_contains($result->output(), 'NOPERM')) {
            throw new AddonException('object_cache_redis_unavailable', __('errors/addons.redis_failed'));
        }
    }
}
