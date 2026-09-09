<?php

use App\Contracts\WebServerDriver;
use App\Enums\BackupStatus;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Certificate;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Worker;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

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

it('removes self-signed certificate files without calling certbot', function () {
    Certificate::create([
        'application_id' => $this->application->id,
        'type' => CertificateType::SelfSigned,
        'status' => CertificateStatus::Active,
        'domains' => ['shop.example.com'],
        'certificate_path' => '/etc/ssl/sv-oss/shop.example.com.crt',
        'private_key_path' => '/etc/ssl/sv-oss/shop.example.com.key',
        'auto_renew' => false,
    ]);

    $ran = teardownCommands();

    app(ApplicationProvisioner::class)->deprovision($this->application->fresh(['systemUser', 'certificate']));

    expect(ranCommand($ran, fn (array $c) => in_array('--cert-name', $c, true)))->toBeFalse()
        ->and(ranCommand($ran, fn (array $c) => ($c[0] ?? '') === 'rm'
            && in_array('/etc/ssl/sv-oss/shop.example.com.crt', $c, true)
            && in_array('/etc/ssl/sv-oss/shop.example.com.key', $c, true)))->toBeTrue();
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

describe('the site\'s backups', function () {
    beforeEach(function () {
        $this->destination = StorageDestination::create([
            'name' => 'Offsite', 'endpoint' => '', 'region' => 'us-east-1',
            'bucket' => 'backups', 'access_key' => 'k', 'secret_key' => 's',
        ]);

        $this->target = BackupTarget::create([
            'application_id' => $this->application->id,
            'storage_destination_id' => $this->destination->id,
            'type' => 'full', 'retention_count' => 7, 'frequency' => 'daily', 'enabled' => true,
        ]);

        $this->disk = Storage::fake('destination');

        $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
            builder: fn (array $config) => $this->disk,
        ));

        $this->disk->put('shop/one.tar.gz', 'archive');

        $this->backup = Backup::create([
            'backup_target_id' => $this->target->id,
            'application_id' => $this->application->id,
            'type' => 'full',
            'status' => BackupStatus::Verified->value,
            'is_safety' => false,
            'manifest' => ['key' => 'shop/one.tar.gz'],
        ]);
    });

    it('keeps the archives when the files were not asked for', function () {
        teardownCommands();

        app(ApplicationProvisioner::class)->deprovision($this->application->fresh(['systemUser', 'certificate']));

        // A backup exists so that a mistaken deletion is survivable, and
        // deleting the site is the mistake somebody would most want to undo.
        // Taking the panel's record of a site off the panel must not destroy
        // the copy that could put it back.
        $this->disk->assertExists('shop/one.tar.gz');
    });

    it('deletes the archives when the caller asked for the data to go', function () {
        teardownCommands();

        app(ApplicationProvisioner::class)
            ->deprovision($this->application->fresh(['systemUser', 'certificate']), removeFiles: true);

        // Otherwise the rows cascade away and multi-gigabyte objects stay in
        // somebody's bucket: unfindable through the panel, undeletable through
        // it, and billed every month.
        $this->disk->assertMissing('shop/one.tar.gz');
        expect(Backup::find($this->backup->id))->toBeNull();
    });

    /**
     * A disk that fails for `$failing` and records every other delete.
     *
     * Mocked against the Filesystem contract rather than hand-rolled, because
     * `DestinationDisk::for()` is typed `: Filesystem` — an anonymous class
     * that does not implement it makes the call raise a TypeError, which
     * `DeleteBackup` catches like any other Throwable. The delete then appears
     * to fail for a reason that has nothing to do with the destination.
     */
    function failingDisk(string $failing, ArrayObject $deleted): Filesystem
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('delete')->with($failing)->andThrow(new RuntimeException('bucket unreachable'));
        $disk->shouldReceive('delete')->andReturnUsing(function (string $key) use ($deleted): bool {
            $deleted[] = $key;

            return true;
        });

        return $disk;
    }

    it('deletes every other archive when one of them will not go', function () {
        // This was one foreach inside one try/catch, so the first failure
        // aborted the loop — and the rows it never reached cascaded away with
        // the application, leaving their objects behind with nothing pointing
        // at them. One unreachable object orphaned every archive for the site.
        $this->disk->put('shop/two.tar.gz', 'archive');
        $this->disk->put('shop/three.tar.gz', 'archive');

        foreach (['shop/two.tar.gz', 'shop/three.tar.gz'] as $key) {
            Backup::create([
                'backup_target_id' => $this->target->id,
                'application_id' => $this->application->id,
                'type' => 'full',
                'status' => BackupStatus::Verified->value,
                'is_safety' => false,
                'manifest' => ['key' => $key],
            ]);
        }

        // ArrayObject, not an array by reference: a promoted constructor
        // property cannot be a reference, so the array version recorded
        // nothing at all.
        $deleted = new ArrayObject;

        $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
            builder: fn (array $config) => failingDisk('shop/one.tar.gz', $deleted),
        ));

        teardownCommands();

        app(ApplicationProvisioner::class)
            ->deprovision($this->application->fresh(['systemUser', 'certificate']), removeFiles: true);

        expect($deleted->getArrayCopy())->toEqualCanonicalizing(['shop/two.tar.gz', 'shop/three.tar.gz'])
            // And the rows for the two that went are gone, so nothing is left
            // pointing at an object that is not there.
            ->and(Backup::where('application_id', $this->application->id)->count())->toBe(1);
    });

    it('names the archives it had to leave behind', function () {
        // The rows are about to cascade with the application, so this warning
        // is the last thing that will ever know these keys. Without them
        // nobody can find the objects the warning is about.
        $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
            builder: fn (array $config) => failingDisk('shop/one.tar.gz', new ArrayObject),
        ));

        // Listened for rather than mocked: deprovisioning logs plenty of other
        // things, and a Log mock turns every one of them into a failure about
        // an unexpected call rather than telling you about this one.
        $logged = new ArrayObject;
        Log::listen(fn ($event) => $logged[] = $event);

        teardownCommands();

        app(ApplicationProvisioner::class)
            ->deprovision($this->application->fresh(['systemUser', 'certificate']), removeFiles: true);

        $orphaned = collect($logged->getArrayCopy())
            ->filter(fn ($event) => ($event->context['artifact'] ?? null) === 'backups')
            ->flatMap(fn ($event) => $event->context['orphaned'] ?? []);

        expect($orphaned->pluck('key')->all())->toContain('shop/one.tar.gz');
    });

    it('still removes the other artefacts when an archive will not delete', function () {
        Certificate::create([
            'application_id' => $this->application->id,
            'type' => CertificateType::LetsEncrypt,
            'status' => CertificateStatus::Active,
            'domains' => ['shop.example.com'],
            'auto_renew' => true,
        ]);

        // Was an anonymous class not implementing Filesystem, so the failure
        // this asserted on was a TypeError from `DestinationDisk::for()`'s
        // return type rather than a destination that would not answer. The
        // assertion held either way, which is exactly why it went unnoticed.
        $this->app->bind(DestinationDisk::class, fn () => new DestinationDisk(
            builder: fn (array $config) => failingDisk('shop/one.tar.gz', new ArrayObject),
        ));

        $ran = teardownCommands();

        app(ApplicationProvisioner::class)
            ->deprovision($this->application->fresh(['systemUser', 'certificate']), removeFiles: true);

        // A bucket that will not answer is a cost problem. Letting it stop the
        // certificate revoke would trade that for a renewal running forever.
        expect(ranCommand($ran, fn (array $c) => in_array('--cert-name', $c, true)))->toBeTrue();
    });
});
