<?php

use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\WebServers\NginxDriver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as LocalProcess;

function rcF01MaxHostname(): string
{
    return implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 61)]);
}

function rcF01Config(string $domain, string $directives = ''): string
{
    $root = test()->nginxRoot;

    return "# Existing configuration: preserve me\nerror_log {$root}/error.log;\npid {$root}/nginx.pid;\n"
        ."events {}\nhttp {\n    # server_names_hash_bucket_size 64; is only a comment\n"
        .$directives."    access_log off;\n    include {$root}/existing.conf;\n"
        ."    server { listen 127.0.0.1:18080; server_name {$domain}; }\n}\n";
}

beforeEach(function () {
    $this->nginxRoot = base_path('.cache/nginx-hash-'.bin2hex(random_bytes(8)));
    File::ensureDirectoryExists($this->nginxRoot);
    $this->nginxPath = $this->nginxRoot.'/nginx.conf';
    $this->existingVhost = "# Existing vhost\nserver { listen 127.0.0.1:18080; server_name existing.example.com; }\n";
    file_put_contents($this->nginxRoot.'/existing.conf', $this->existingVhost);
    file_put_contents($this->nginxPath, rcF01Config('fresh-wordpress-container.23-172-120-119.nip.io'));
    $this->nginxChecks = [];
    $this->readFailure = false;
    $this->writeFailure = false;
    $this->afterRepairFailure = false;
    $this->shortHostname = false;
    $this->forceHashDiagnostic = false;
    $this->expireDiscovery = false;
    $this->interleaveRepair = false;
    $this->otherWriterAcquired = null;
    config([
        'server.web_server' => 'nginx',
        'server.web_server_drivers.nginx.config_path' => $this->nginxPath,
        'server.web_server_drivers.nginx.sites_available_dir' => $this->nginxRoot.'/available',
        'server.web_server_drivers.nginx.sites_dir' => $this->nginxRoot.'/enabled',
    ]);

    // No host command is executed by the facade. The opt-in independent check
    // below runs ONLY nginx -t against this disposable prefix/config, never a
    // daemon, /etc/nginx, sudo, a reload or a remote host.
    Process::fake(function (PendingProcess $process) {
        if ($process->command === ['nginx', '-t']) {
            if ($this->forceHashDiagnostic) {
                $result = Process::result(errorOutput: 'could not build server_names_hash, you should increase server_names_hash_bucket_size: 64', exitCode: 1);
            } elseif ($this->afterRepairFailure && str_contains(file_get_contents($this->nginxPath), '512')) {
                if ($this->interleaveRepair) {
                    $this->travel(31)->seconds();
                    $other = Cache::lock('nginx-server-name-hash', 600);
                    $this->otherWriterAcquired = $other->get();
                    if ($this->otherWriterAcquired) {
                        file_put_contents($this->nginxPath, "# another writer succeeded\n", FILE_APPEND);
                        $other->release();
                    }
                }
                $result = Process::result(errorOutput: 'unknown directive "unrelated_problem"', exitCode: 1);
            } elseif ($binary = getenv('RC_F01_NGINX_BINARY')) {
                $local = new LocalProcess([$binary, '-t', '-p', $this->nginxRoot.'/', '-c', $this->nginxPath]);
                $local->setTimeout(10)->run();
                $result = Process::result(output: $local->getOutput(), errorOutput: $local->getErrorOutput(), exitCode: $local->getExitCode());
            } else {
                $contents = collect(File::allFiles($this->nginxRoot))
                    ->filter(fn ($file) => $file->getExtension() === 'conf')
                    ->map(fn ($file) => file_get_contents($file->getPathname()))->implode("\n");
                // Explicit test double, not the product parser or proof that
                // nginx accepts the output. Real nginx sensitivity is opt-in.
                $ok = $this->shortHostname || preg_match('/^\s*server_names_hash_bucket_size\s+[\'\"]?(512|1024)[\'\"]?;/m', $contents) === 1;
                $result = Process::result(errorOutput: $ok ? '' : 'could not build server_names_hash, you should increase server_names_hash_bucket_size: 64', exitCode: $ok ? 0 : 1);
            }
            $this->nginxChecks[] = $result->exitCode();

            return $result;
        }
        if ($process->command[0] === 'cat' && str_starts_with($process->command[1], $this->nginxRoot.'/')) {
            if ($this->expireDiscovery) {
                $this->expireDiscovery = false;
                $this->travel(31)->seconds();
            }

            return $this->readFailure
                ? Process::result(errorOutput: 'permission denied', exitCode: 1)
                : Process::result(output: file_get_contents($process->command[1]));
        }
        if ($process->command[0] === 'tee' && str_starts_with($process->command[1], $this->nginxRoot.'/')) {
            File::ensureDirectoryExists(dirname($process->command[1]));
            file_put_contents($process->command[1], $this->writeFailure ? 'partial write' : $process->input);
            if ($this->writeFailure) {
                $this->writeFailure = false;

                return Process::result(errorOutput: 'write failed', exitCode: 1);
            }
        }

        return Process::result();
    });
});

afterEach(function () {
    $this->travelBack();
    File::deleteDirectory($this->nginxRoot);
});

it('accepts the valid long hostname at the existing create validation gate', function (string $domain) {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $user = SystemUser::create(['username' => 'hashsite', 'home_path' => $this->nginxRoot.'/home']);
    ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now()]);
    Queue::fake();

    $this->actingAs($admin)->postJson('/api/applications', [
        'name' => 'Hash regression', 'domain' => $domain, 'site_type' => 'static',
        'system_user_id' => $user->id, 'web_root' => '/',
    ])->assertCreated();
    Queue::assertPushed(ProvisionApplication::class);
})->with(['fresh-wordpress-container.23-172-120-119.nip.io', fn () => rcF01MaxHostname()]);

it('rejects invalid or injection hostnames without reaching provisioning', function (string $domain) {
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $user = SystemUser::create(['username' => 'hashsite', 'home_path' => $this->nginxRoot.'/home']);
    ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now()]);
    Queue::fake();

    $this->actingAs($admin)->postJson('/api/applications', [
        'name' => 'Hash regression', 'domain' => $domain, 'site_type' => 'static',
        'system_user_id' => $user->id, 'web_root' => '/',
    ])->assertUnprocessable()->assertJsonValidationErrors('domain');
    Queue::assertNotPushed(ProvisionApplication::class);
    Process::assertNotRan(fn ($p) => $p->command === ['nginx', '-t']);
})->with(['evil.example.com; server_names_hash_bucket_size 1;', "evil.exa\nmple.com", '-bad.example.com', 'a..example.com', fn () => 'a'.rcF01MaxHostname()]);

it('repairs the actual failed long-name gate and preserves configuration and prior vhosts', function (string $domain) {
    $original = rcF01Config($domain);
    file_put_contents($this->nginxPath, $original);
    expect(app(NginxDriver::class)->test()->ok)->toBeTrue();
    $repaired = file_get_contents($this->nginxPath);
    expect($this->nginxChecks[0])->toBe(1)
        ->and(end($this->nginxChecks))->toBe(0)
        ->and(str_replace("\n    server_names_hash_bucket_size 512;", '', $repaired))->toBe($original)
        ->and(file_get_contents($this->nginxRoot.'/existing.conf'))->toBe($this->existingVhost);
    expect(app(NginxDriver::class)->test()->ok)->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($repaired);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'systemctl');
    Process::assertRanTimes(fn ($p) => $p->command === ['tee', $this->nginxPath], 1);
})->with(['fresh-wordpress-container.23-172-120-119.nip.io', fn () => rcF01MaxHostname()]);

it('leaves an ordinary short hostname and all existing directives byte-identical', function () {
    $original = rcF01Config('shop.example.com');
    file_put_contents($this->nginxPath, $original);
    $this->shortHostname = true;
    expect(app(NginxDriver::class)->test()->ok)->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($original);
    Process::assertNotRan(fn ($p) => in_array($p->command[0], ['cat', 'tee'], true));
});

it('merges an explicit undersized directive without replacing comments or other bytes', function (string $argument) {
    $original = rcF01Config('fresh-wordpress-container.23-172-120-119.nip.io', "    server_names_hash_bucket_size\t{$argument}; # deliberately configured\n");
    file_put_contents($this->nginxPath, $original);
    expect(app(NginxDriver::class)->test()->ok)->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe(str_replace("size\t{$argument};", "size\t".str_replace('64', '512', $argument).';', $original));
})->with(['64', "'64'", '"64"']);

it('updates an included explicit directive instead of inserting a conflicting second one', function () {
    $included = $this->nginxRoot.'/hash.conf';
    $original = "# Keep included configuration\nserver_names_hash_bucket_size 64; # keep\n";
    file_put_contents($included, $original);
    $main = rcF01Config('fresh-wordpress-container.23-172-120-119.nip.io', "    include hash.conf;\n");
    file_put_contents($this->nginxPath, $main);
    expect(app(NginxDriver::class)->test()->ok)->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($main)
        ->and(file_get_contents($included))->toBe(str_replace('size 64;', 'size 512;', $original));
});

it('finds an explicit directive through a quoted wildcard include', function () {
    File::ensureDirectoryExists($this->nginxRoot.'/conf.d');
    $path = $this->nginxRoot.'/conf.d/hash.conf';
    file_put_contents($path, "server_names_hash_bucket_size 64;\n");
    $main = rcF01Config('fresh-wordpress-container.23-172-120-119.nip.io', "    include \"{$this->nginxRoot}/conf.d/*.conf\";\n");
    file_put_contents($this->nginxPath, $main);
    expect(app(NginxDriver::class)->test()->ok)->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($main)
        ->and(file_get_contents($path))->toBe("server_names_hash_bucket_size 512;\n");
});

it('refuses ambiguous or unsupported configuration without writing guessed settings', function (string $layout) {
    $original = match ($layout) {
        'no http' => "events {}\n",
        'two directives' => rcF01Config('example.com', "    server_names_hash_bucket_size 64;\n    server_names_hash_bucket_size 32;\n"),
        'variable include' => rcF01Config('example.com', '    include $custom/*.conf;'."\n"),
        'include cycle' => rcF01Config('example.com', "    include cycle.conf;\n"),
    };
    file_put_contents($this->nginxRoot.'/cycle.conf', "include cycle.conf;\n");
    file_put_contents($this->nginxPath, $original);
    $this->forceHashDiagnostic = true;
    expect(app(NginxDriver::class)->test()->failed())->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($original);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
})->with(['no http', 'two directives', 'variable include', 'include cycle']);

it('preserves an adequate explicit larger setting without reading or writing configuration', function () {
    $original = rcF01Config(rcF01MaxHostname(), "    server_names_hash_bucket_size 1024; # owner choice\n");
    file_put_contents($this->nginxPath, $original);
    expect(app(NginxDriver::class)->test()->ok)->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($original);
    Process::assertNotRan(fn ($p) => in_array($p->command[0], ['cat', 'tee'], true));
});

it('does not mistake quoted braces or directive text for live http settings', function () {
    $original = rcF01Config('fresh-wordpress-container.23-172-120-119.nip.io', "    log_format quoted 'http { server_names_hash_bucket_size 64; }';\n");
    file_put_contents($this->nginxPath, $original);
    expect(app(NginxDriver::class)->test()->ok)->toBeTrue()
        ->and(str_replace("\n    server_names_hash_bucket_size 512;", '', file_get_contents($this->nginxPath)))->toBe($original);
});

it('refuses an unreadable configuration without replacing it with emptiness', function () {
    $original = file_get_contents($this->nginxPath);
    $this->readFailure = true;
    expect(app(NginxDriver::class)->test()->failed())->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($original);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('restores original bytes after a partial write fails', function () {
    $original = file_get_contents($this->nginxPath);
    $this->writeFailure = true;
    expect(app(NginxDriver::class)->test()->failed())->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($original);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'systemctl');
});

it('restores original bytes and does not reload if the repaired test fails for another reason', function () {
    $original = file_get_contents($this->nginxPath);
    $this->afterRepairFailure = true;
    $result = app(NginxDriver::class)->test();
    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('unrelated_problem')
        ->and(file_get_contents($this->nginxPath))->toBe($original);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'systemctl');
});

it('keeps the repair serialized beyond the old lease through a failed test and rollback', function () {
    $original = file_get_contents($this->nginxPath);
    $this->afterRepairFailure = true;
    $this->interleaveRepair = true;
    expect(app(NginxDriver::class)->test()->failed())->toBeTrue()
        ->and($this->otherWriterAcquired)->toBeFalse()
        ->and(file_get_contents($this->nginxPath))->toBe($original);
});

it('refuses to start a write after the bounded discovery budget has elapsed', function () {
    $original = file_get_contents($this->nginxPath);
    $this->expireDiscovery = true;
    expect(app(NginxDriver::class)->test()->failed())->toBeTrue()
        ->and(file_get_contents($this->nginxPath))->toBe($original);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('does not treat a lookalike substring in another nginx error as the capacity diagnostic', function () {
    Process::fake(fn ($p) => Process::result(errorOutput: 'nginx: [emerg] unknown directive "could not build server_names_hash, you should increase server_names_hash_bucket_size: 64"', exitCode: 1));
    expect(app(NginxDriver::class)->test()->failed())->toBeTrue();
    Process::assertNotRan(fn ($p) => in_array($p->command[0], ['cat', 'tee'], true));
});

it('leaves unrelated nginx failures untouched', function () {
    Process::fake(fn ($p) => $p->command === ['nginx', '-t']
        ? Process::result(errorOutput: 'unknown directive "unrelated_problem"', exitCode: 1)
        : Process::result());
    expect(app(NginxDriver::class)->test()->failed())->toBeTrue();
    Process::assertNotRan(fn ($p) => $p->command[0] === 'tee');
});

it('continues provisioning only after the long-domain config is repaired and tested', function () {
    $user = SystemUser::create(['username' => 'hashsite', 'home_path' => $this->nginxRoot.'/home']);
    $application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Hash regression', 'slug' => 'hashregression',
        'domain' => 'fresh-wordpress-container.23-172-120-119.nip.io',
        'site_type' => 'static', 'serving_profile' => 'static', 'web_root' => '/', 'status' => 'pending',
    ]);
    Queue::fake();
    (new ProvisionApplication($application->id))->handle(app(ApplicationProvisioner::class), app(ActivityLogger::class));
    expect($application->fresh()->status->value)->toBe('active')
        ->and($this->nginxChecks[0])->toBe(1)
        ->and(end($this->nginxChecks))->toBe(0);
    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'nginx']);
});
