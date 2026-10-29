<?php

use App\Services\Panel\UpdateScript;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * install.sh wrote `maxmemory-policy allkeys-lru`, and the Redis screen refuses
 * every allkeys-* policy while the queue is on Redis — eviction could delete
 * queued jobs — so the screen could not be saved on a fresh install (found
 * 28 Oct). A fresh install now writes volatile-lru; the panel's update moves
 * existing servers, and only the installer's own line.
 */

const REDIS_POLICY_CONF = '/etc/redis/redis.conf';

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sv-oss-redis-policy-'.getmypid();
    File::ensureDirectoryExists($this->dir);
    File::put($this->dir.'/redis-cli', '');

    config(['server.redis_cli' => $this->dir.'/redis-cli', 'server.redis_conf' => REDIS_POLICY_CONF, 'database.redis.default.password' => null]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** redis.conf as the fake root reads and writes it, and what redis-cli was asked. */
function redisPolicyDisk(string $conf, string $cliAnswer = "OK\n"): ArrayObject
{
    $disk = new ArrayObject(['conf' => $conf, 'writes' => 0, 'cli' => []]);

    Process::fake(function ($process) use ($disk, $cliAnswer) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return match (true) {
            $args === ['cat', REDIS_POLICY_CONF] => Process::result(output: $disk['conf']),
            $args === ['tee', REDIS_POLICY_CONF] => (function () use ($disk, $process) {
                $disk['conf'] = (string) $process->input;
                $disk['writes']++;

                return Process::result();
            })(),
            str_ends_with((string) $args[0], 'redis-cli') => (function () use ($disk, $args, $cliAnswer) {
                $disk['cli'] = [...$disk['cli'], implode(' ', array_slice($args, 1))];

                return Process::result(output: $cliAnswer);
            })(),
            default => Process::result(),
        };
    });

    return $disk;
}

function installerRedisConf(string $policy = 'allkeys-lru'): string
{
    return "bind 127.0.0.1 -::1\nport 6379\n# maxmemory-policy noeviction\n\n# Added by the Control panel installer\nrequirepass Secret-0123456789\n\n# Added by the Control panel installer\nmaxmemory 256mb\nmaxmemory-policy {$policy}\n";
}

it('switches the installer\'s allkeys-lru to volatile-lru, in redis.conf and live', function () {
    $disk = redisPolicyDisk(installerRedisConf());

    $this->artisan('redis:repair-installer-policy')
        ->expectsOutputToContain('switched from allkeys-lru to volatile-lru')
        ->assertSuccessful();

    // That one line, and nothing else in the file.
    expect($disk['conf'])->toBe(installerRedisConf('volatile-lru'))
        ->and($disk['cli'])->toBe(['config set maxmemory-policy volatile-lru']);
});

it('changes nothing the second time', function () {
    $disk = redisPolicyDisk(installerRedisConf('volatile-lru'));

    $this->artisan('redis:repair-installer-policy')->assertSuccessful();

    expect($disk['writes'])->toBe(0)->and($disk['cli'])->toBe([]);
});

it('leaves a policy the operator chose alone', function (string $conf) {
    $disk = redisPolicyDisk($conf);

    $this->artisan('redis:repair-installer-policy')
        ->expectsOutputToContain('nothing to change')
        ->assertSuccessful();

    expect($disk['conf'])->toBe($conf)
        ->and($disk['writes'])->toBe(0)
        ->and($disk['cli'])->toBe([]);
})->with([
    'set again further down, which is the one Redis reads' => [installerRedisConf()."maxmemory-policy allkeys-lfu\n"],
    'the installer\'s line edited to another policy' => [installerRedisConf('allkeys-lfu')],
    'allkeys-lru, but not under the installer\'s marker' => ["port 6379\nmaxmemory 512mb\nmaxmemory-policy allkeys-lru\n"],
    'no policy at all' => ["port 6379\n"],
]);

it('reports a Redis that refuses the live change, after the file is already right', function () {
    $disk = redisPolicyDisk(installerRedisConf(), "NOAUTH Authentication required.\n");

    $this->artisan('redis:repair-installer-policy')->assertFailed();

    // The next restart comes up on volatile-lru anyway.
    expect($disk['conf'])->toBe(installerRedisConf('volatile-lru'));
});

it('does nothing where Redis is not installed', function () {
    File::delete($this->dir.'/redis-cli');
    $disk = redisPolicyDisk(installerRedisConf());

    $this->artisan('redis:repair-installer-policy')->assertSuccessful();

    expect($disk['conf'])->toBe(installerRedisConf())->and($disk['cli'])->toBe([]);
    Process::assertNothingRan();
});

it('runs on every panel update, after migrate, and never fails the update', function () {
    $steps = array_flip(UpdateScript::STEPS);

    expect($steps)->toHaveKey('repair_redis_policy')
        ->and($steps['repair_redis_policy'])->toBeGreaterThan($steps['migrate'])
        ->and(file_get_contents(base_path('app/Services/Panel/UpdateScript.php')))
        ->toContain('note repair_redis_policy')
        ->toContain('artisan redis:repair-installer-policy || echo');

    foreach (['en', 'de', 'es', 'fr', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        expect(trans('panel_update.steps.repair_redis_policy', [], $locale))->not->toBe('panel_update.steps.repair_redis_policy')
            ->and(trans('panel_update.reasons.repair_redis_policy', [], $locale))->not->toBe('panel_update.reasons.repair_redis_policy');
    }
});
