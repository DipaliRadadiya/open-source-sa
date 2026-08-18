<?php

use App\Contracts\WebServerDriver;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Worker;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * What the panel wrote for a site, and whether it goes when the site does.
 *
 * Everything here lives *outside* the application's own directory, so removing
 * the site leaves it behind pointing at something that no longer exists. The
 * endings differ in severity but not in kind, and the FPM pool already proved
 * how bad the worst of them gets: one orphan stopped php-fpm initialising for
 * the entire server, and every later site creation was blamed for it.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->admin()->create();

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
        'isolated_at' => now(),
    ]);

    $this->application->domains()->create([
        'domain' => 'shop.example.com',
        'type' => DomainType::Primary,
    ]);

    $driver = Mockery::mock(WebServerDriver::class);
    $driver->shouldReceive('remove')->andReturn(new ServerOpsResult(true, 'ref', null));
    $driver->shouldReceive('test')->andReturn(new ServerOpsResult(true, 'ref', null));
    $driver->shouldReceive('reload')->andReturn(new ServerOpsResult(true, 'ref', null));

    $manager = Mockery::mock(WebServerManager::class);
    $manager->shouldReceive('driver')->andReturn($driver);
    app()->instance(WebServerManager::class, $manager);
});

/** Every command the teardown ran. */
function teardownCommands(): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran) {
        $ran[] = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return Process::result(exitCode: 0);
    });

    return $ran;
}

function ranCommand(ArrayObject $ran, callable $matches): bool
{
    foreach ($ran as $command) {
        if ($matches($command)) {
            return true;
        }
    }

    return false;
}

it('stops the certificate renewing', function () {
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com', 'www.shop.example.com'],
        'auto_renew' => true,
    ]);

    $ran = teardownCommands();

    app(ApplicationProvisioner::class)->deprovision($this->application->fresh(['systemUser', 'certificate']));

    // RemoveCertificate has always known what happens otherwise — "the renewal
    // keeps running forever, keeps spending rate limit, and eventually emails
    // the user about a site they removed" — it was just never called from the
    // path where sites actually go away. The lineage is named after the first
    // domain.
    expect(ranCommand($ran, fn (array $c) => in_array('delete', $c, true)
        && in_array('--cert-name', $c, true)
        && in_array('shop.example.com', $c, true)))->toBeTrue();
});

it('leaves a self-signed certificate alone, because certbot never had it', function () {
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::SelfSigned,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'auto_renew' => false,
    ]);

    $ran = teardownCommands();

    app(ApplicationProvisioner::class)->deprovision($this->application->fresh(['systemUser', 'certificate']));

    expect(ranCommand($ran, fn (array $c) => in_array('--cert-name', $c, true)))->toBeFalse();
});

it('removes the worker units the cascade would otherwise orphan', function () {
    Worker::create([
        'application_id' => $this->application->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'processes' => 1,
        'auto_restart' => true,
    ]);

    $ran = teardownCommands();

    app(ApplicationProvisioner::class)->deprovision($this->application->fresh(['systemUser', 'certificate']));

    // `workers.application_id` is cascadeOnDelete, so the rows vanish with the
    // application and take the only record of these units with them. The unit
    // would stay enabled and restart on boot, holding a deleted site's files.
    expect(ranCommand($ran, fn (array $c) => ($c[0] ?? '') === 'systemctl'
        && in_array('stop', $c, true)))->toBeTrue();

    expect(ranCommand($ran, fn (array $c) => ($c[0] ?? '') === 'systemctl'
        && in_array('daemon-reload', $c, true)))->toBeTrue();
});

it('removes the fail2ban jail, which points at a log that is going away', function () {
    $ran = teardownCommands();

    app(ApplicationProvisioner::class)->deprovision($this->application->fresh(['systemUser', 'certificate']));

    expect(ranCommand($ran, fn (array $c) => ($c[0] ?? '') === 'rm'
        && str_contains(implode(' ', $c), 'jail.d')))->toBeTrue();
});

describe('a certificate that outlived its domains', function () {
    it('reports names the site no longer has, because they break renewal', function () {
        $certificate = Certificate::create([
            'application_id' => $this->application->id,
            'type' => CertificateType::LetsEncrypt,
            'status' => CertificateStatus::Active,
            'domains' => ['shop.example.com', 'old.example.com'],
            'auto_renew' => true,
        ]);

        // `old.example.com` was removed from the site. certbot re-validates
        // every name in a lineage and fails the whole renewal if any one of
        // them cannot be validated — so this certificate has silently stopped
        // renewing for shop.example.com, which is perfectly fine.
        expect($certificate->fresh()->staleDomains())->toBe(['old.example.com']);

        // The opposite direction, which was already reported, is a name
        // without HTTPS — visible the moment somebody visits it.
        expect($certificate->fresh()->missingDomains())->toBe([]);
    });

    it('says nothing about a certificate that nothing renews', function () {
        $certificate = Certificate::create([
            'application_id' => $this->application->id,
            'type' => CertificateType::SelfSigned,
            'status' => CertificateStatus::Active,
            'domains' => ['shop.example.com', 'old.example.com'],
            'auto_renew' => false,
        ]);

        // Only Let's Encrypt renews, so only Let's Encrypt can break this way.
        expect($certificate->fresh()->staleDomains())->toBe([]);
    });

    it('is quiet when the certificate matches the site', function () {
        $certificate = Certificate::create([
            'application_id' => $this->application->id,
            'type' => CertificateType::LetsEncrypt,
            'status' => CertificateStatus::Active,
            'domains' => ['shop.example.com'],
            'auto_renew' => true,
        ]);

        expect($certificate->fresh()->staleDomains())->toBe([]);
    });
});

it('keeps going when one artefact refuses to be removed', function () {
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'auto_renew' => true,
    ]);

    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran) {
        $command = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran[] = $command;

        // fail2ban is unhappy. That is a nuisance; letting it skip the
        // certificate revoke would trade it for a renewal running forever.
        if (str_contains(implode(' ', $command), 'fail2ban')) {
            return Process::result(errorOutput: 'fail2ban-client: not found', exitCode: 127);
        }

        return Process::result(exitCode: 0);
    });

    app(ApplicationProvisioner::class)->deprovision($this->application->fresh(['systemUser', 'certificate']));

    expect(ranCommand($ran, fn (array $c) => in_array('--cert-name', $c, true)))->toBeTrue();
});
