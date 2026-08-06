<?php

use App\Services\Panel\UpdateScript;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Pointing the queue and sessions at Redis, safely, on somebody else's server.
 *
 * SQLite allows exactly one writer. With the queue on the database the worker
 * polls the same file every request writes to, and with sessions there every
 * authenticated request adds a write — enough to make an idle single-user
 * panel return "database is locked" on whichever request lost the race.
 *
 * The installer can only fix that for new installs; an update never rewrites
 * `.env`. So this runs on every update, which is exactly why it has to be
 * conservative: prove Redis first, never overwrite a deliberate choice, and
 * never move the queue out from under jobs that are still sitting in it.
 */
beforeEach(function () {
    $this->envPath = base_path('.env');
    $this->original = is_file($this->envPath) ? file_get_contents($this->envPath) : null;
});

afterEach(function () {
    if ($this->original !== null) {
        file_put_contents($this->envPath, $this->original);
    } elseif (is_file($this->envPath)) {
        unlink($this->envPath);
    }
});

function writeConfigureEnv(string $contents): void
{
    file_put_contents(test()->envPath, $contents);
}

function configureEnvContents(): string
{
    return (string) file_get_contents(test()->envPath);
}

function fakeConfigureRedis(bool $answers): void
{
    if ($answers) {
        Redis::shouldReceive('connection')->andReturnSelf();
        Redis::shouldReceive('ping')->andReturn(true);

        return;
    }

    Redis::shouldReceive('connection')->andThrow(new RuntimeException('NOAUTH'));
}

it('moves the queue, sessions and cache to redis', function () {
    writeConfigureEnv("APP_ENV=production\nQUEUE_CONNECTION=database\nSESSION_DRIVER=database\nCACHE_STORE=database\n");
    fakeConfigureRedis(true);

    $this->artisan('panel:configure-services')->assertSuccessful();

    expect(configureEnvContents())
        ->toContain('QUEUE_CONNECTION=redis')
        ->toContain('SESSION_DRIVER=redis')
        ->toContain('CACHE_STORE=redis')
        // Everything else survives untouched — this edits a value, it does not
        // regenerate the file.
        ->toContain('APP_ENV=production');
});

it('changes nothing when redis does not answer', function () {
    writeConfigureEnv("QUEUE_CONNECTION=database\nSESSION_DRIVER=database\n");
    fakeConfigureRedis(false);

    $this->artisan('panel:configure-services')->assertSuccessful();

    // Pointing the panel at a Redis that just failed to answer would trade an
    // intermittent lock error for a total outage.
    expect(configureEnvContents())
        ->toContain('QUEUE_CONNECTION=database')
        ->toContain('SESSION_DRIVER=database');
});

it('never overwrites a value the operator chose', function () {
    writeConfigureEnv("QUEUE_CONNECTION=sqs\nSESSION_DRIVER=cookie\nCACHE_STORE=database\n");
    fakeConfigureRedis(true);

    $this->artisan('panel:configure-services')->assertSuccessful();

    // Self-hosted: only a value still at the shipped default is ours to move.
    expect(configureEnvContents())
        ->toContain('QUEUE_CONNECTION=sqs')
        ->toContain('SESSION_DRIVER=cookie')
        ->toContain('CACHE_STORE=redis');
});

it('refuses to move the queue while jobs are still sitting in it', function () {
    writeConfigureEnv("QUEUE_CONNECTION=database\nSESSION_DRIVER=database\n");
    fakeConfigureRedis(true);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $this->artisan('panel:configure-services')->assertSuccessful();

    // `queue:work` reads the default connection, so switching it here would
    // orphan that job silently — no error, it simply never runs.
    expect(configureEnvContents())->toContain('QUEUE_CONNECTION=database')
        // Sessions have no such hazard and still move.
        ->and(configureEnvContents())->toContain('SESSION_DRIVER=redis');
});

it('is a no-op the second time', function () {
    writeConfigureEnv("QUEUE_CONNECTION=redis\nSESSION_DRIVER=redis\nCACHE_STORE=redis\n");
    fakeConfigureRedis(true);

    $before = configureEnvContents();

    $this->artisan('panel:configure-services')
        ->expectsOutputToContain('Already configured')
        ->assertSuccessful();

    expect(configureEnvContents())->toBe($before);
});

it('reports without writing under --dry-run', function () {
    writeConfigureEnv("QUEUE_CONNECTION=database\n");
    fakeConfigureRedis(true);

    $this->artisan('panel:configure-services --dry-run')->assertSuccessful();

    expect(configureEnvContents())->toContain('QUEUE_CONNECTION=database');
});

it('runs during an update, before the config is cached', function () {
    $steps = array_flip(UpdateScript::STEPS);

    expect($steps)->toHaveKey('configure_services')
        // `optimize` caches the config this rewrites; running after it would
        // write a correct .env that nothing reads until the next update.
        ->and($steps['configure_services'])->toBeLessThan($steps['optimize'])
        // And after migrate, because it counts rows in the jobs table.
        ->and($steps['configure_services'])->toBeGreaterThan($steps['migrate']);
});
