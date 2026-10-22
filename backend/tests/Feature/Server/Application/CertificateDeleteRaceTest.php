<?php

use App\Actions\Server\Application\ApplyVhost;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Jobs\IssueCertificate;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\Certificates\CertificateLock;
use App\Services\Server\WebServers\WebServerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * Issuing a site's certificate while the site is being deleted (2026-10-21,
 * found live on the Apache test server).
 *
 * The delete's `certbot delete` failed with "Another instance of Certbot is
 * already running" because the issuance held certbot's own lock; the delete
 * went on and removed the site; certbot then finished and created a lineage
 * for a site that no longer existed, and the job crashed reading it — a failed
 * job and a certificate renewing forever for nothing.
 *
 * Pinned here: the two take turns on the site's name (CertificateLock); a job
 * finding the lock held goes back on the queue rather than waiting inside the
 * worker; whatever an issuance made for a site deleted some other way is
 * removed — unless a live site now owns that name — and never by guessing at
 * another site's certificate.
 *
 * Nothing here runs certbot: every process goes to the fake host below, which
 * creates a lineage only when `certonly` finishes, as certbot does.
 */
class CertRaceHost
{
    /** @var array<string, true> lineages on disk */
    public static array $lineages = [];

    /** @var array<string, true> self-signed files on disk */
    public static array $files = [];

    /** @var array<int, array<int, string>> */
    public static array $commands = [];

    /** @var array<int, string> `certbot <verb> <name>` keys answering "Another instance" (each entry once) */
    public static array $busy = [];

    /** Run while certbot/openssl is "working", before anything is on disk. */
    public static ?Closure $during = null;

    public static ?string $certonlyFails = null;

    /** @var array<string, Closure> run once, when a command line first contains the key */
    public static array $on = [];

    public static function fake(): void
    {
        self::$lineages = [];
        self::$files = [];
        self::$commands = [];
        self::$busy = [];
        self::$during = null;
        self::$certonlyFails = null;
        self::$on = [];

        Process::fake(fn ($process) => self::answer($process));
    }

    public static function answer($process)
    {
        $args = array_values((array) $process->command);

        while (in_array($args[0] ?? '', ['sudo', '-n'], true)) {
            array_shift($args);
        }

        self::$commands[] = $args;

        foreach (self::$on as $needle => $hook) {
            if (str_contains(implode(' ', $args), $needle)) {
                unset(self::$on[$needle]);
                $hook();
            }
        }

        if (in_array('-enddate', $args, true)) {
            return Process::result(output: 'notAfter=Jan  1 00:00:00 2030 GMT');
        }

        if (($args[0] ?? '') === 'test' && in_array('-e', $args, true)) {
            return Process::result(exitCode: isset(self::$files[$args[2] ?? '']) ? 0 : 1);
        }

        if (str_ends_with($args[0] ?? '', 'certbot')) {
            $verb = $args[1] ?? '';
            $name = $args[array_search('--cert-name', $args, true) + 1] ?? '';

            if (($index = array_search("{$verb} {$name}", self::$busy, true)) !== false) {
                unset(self::$busy[$index]);

                return Process::result(errorOutput: "Another instance of Certbot is already running.\n", exitCode: 1);
            }

            if ($verb === 'certonly') {
                self::working();

                if (self::$certonlyFails !== null) {
                    return Process::result(errorOutput: self::$certonlyFails, exitCode: 1);
                }

                self::$lineages[$name] = true;

                return Process::result(output: 'Successfully received certificate.');
            }

            if ($verb === 'delete') {
                if (! isset(self::$lineages[$name])) {
                    return Process::result(errorOutput: "No certificate found with name {$name}", exitCode: 1);
                }

                unset(self::$lineages[$name]);

                return Process::result(output: 'Deleted all files relating to certificate '.$name);
            }

            return Process::result();
        }

        if (($args[0] ?? '') === 'openssl' && in_array('-keyout', $args, true)) {
            self::working();
            self::$files[$args[array_search('-keyout', $args, true) + 1]] = true;
            self::$files[$args[array_search('-out', $args, true) + 1]] = true;

            return Process::result();
        }

        if (($args[0] ?? '') === 'rm') {
            foreach (array_slice($args, 1) as $path) {
                unset(self::$files[$path]);
            }

            return Process::result();
        }

        return Process::result();
    }

    private static function working(): void
    {
        if (self::$during !== null) {
            $during = self::$during;
            self::$during = null;
            $during();
        }
    }

    /** @return array<int, string> */
    public static function lines(): array
    {
        return array_map(fn (array $c): string => implode(' ', $c), self::$commands);
    }

    /** @return array<int, string> every certbot delete, by lineage name */
    public static function deleted(): array
    {
        return array_values(array_map(
            fn (array $c): string => $c[array_search('--cert-name', $c, true) + 1],
            array_filter(self::$commands, fn (array $c): bool => str_ends_with($c[0], 'certbot') && ($c[1] ?? '') === 'delete'),
        ));
    }

    /** Anything that puts a certificate in front of a site. */
    public static function activated(): bool
    {
        foreach (self::lines() as $line) {
            if (preg_match('#^(tee /.*sites-available|nginx -t|systemctl reload)#', $line) === 1) {
                return true;
            }
        }

        return false;
    }
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->home = sys_get_temp_dir().'/sv-oss-certrace-'.getmypid();
    config([
        'server.web_server' => 'nginx',
        'server.web_server_drivers.nginx.sites_dir' => $this->home.'/sites',
        'server.web_server_drivers.nginx.sites_available_dir' => $this->home.'/sites-available',
        'server.certificates.lock.delete_wait' => 1,
        'server.transient.delay_ms' => 1,
    ]);

    $this->owner = SystemUser::create(['username' => 'certuser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false]);
    $this->application = raceSite('shop', 'shop.example.com');

    CertRaceHost::fake();
});

function raceSite(string $slug, string $domain): Application
{
    $application = Application::forceCreate([
        'system_user_id' => test()->owner->id,
        'name' => $slug,
        'slug' => $slug,
        'domain' => $domain,
        'site_type' => 'php',
        'serving_profile' => 'php',
        'php_version' => '8.4',
        'web_root' => '/',
        'status' => 'active',
    ]);

    $application->domains()->create([
        'domain' => $domain,
        'type' => DomainType::Primary,
        'dns_verified_at' => now(),
        'dns_resolved_ip' => '203.0.113.10',
    ]);

    return $application;
}

function pendingCertificate(Application $application, CertificateType $type = CertificateType::LetsEncrypt): Certificate
{
    return Certificate::create([
        'application_id' => $application->id,
        'type' => $type,
        'status' => CertificateStatus::Pending,
        'domains' => [$application->domain],
    ]);
}

function runRaceJob(IssueCertificate $job, ?InstallerManager $installers = null): void
{
    $job->handle(
        app(CertbotClient::class),
        app(CertificateFiles::class),
        app(ApplyVhost::class),
        app(WebServerManager::class),
        app(ActivityLogger::class),
        $installers,
    );
}

function deleteRaceSite(Application $application, string $query = 'remove_files=true')
{
    return test()->actingAs(test()->admin)->deleteJson("/api/applications/{$application->id}?{$query}");
}

function lockFree(string $name): bool
{
    $lock = Cache::lock('certificate:'.$name, 5);

    if (! $lock->get()) {
        return false;
    }

    $lock->release();

    return true;
}

describe('a site deleted before the job starts', function () {
    it('creates nothing and does not fail', function () {
        $certificate = pendingCertificate($this->application);
        deleteRaceSite($this->application)->assertOk();
        CertRaceHost::$commands = [];

        runRaceJob(new IssueCertificate($certificate->id));

        expect(CertRaceHost::$commands)->toBe([])
            ->and(CertRaceHost::$lineages)->toBe([])
            ->and(Certificate::find($certificate->id))->toBeNull();
    });
});

describe('a site delete while its certificate is being issued', function () {
    it('waits, answers busy with nothing changed, and the next delete removes the lineage', function () {
        $certificate = pendingCertificate($this->application);
        $seen = [];

        // The delete arrives while certbot is talking to Let's Encrypt.
        CertRaceHost::$during = function () use (&$seen) {
            $before = count(CertRaceHost::$commands);
            $response = deleteRaceSite($this->application, 'remove_files=true&remove_databases=true');
            $seen = [
                'status' => $response->status(),
                'code' => $response->json('code'),
                'reference' => $response->json('reference'),
                'commands' => array_slice(CertRaceHost::lines(), $before),
                'exists' => Application::whereKey($this->application->id)->exists(),
            ];
        };

        runRaceJob(new IssueCertificate($certificate->id));

        // Nothing at all ran for the delete — not even the stranded-install
        // close or a read of the server.
        expect($seen['status'])->toBe(503)
            ->and($seen['code'])->toBe('server_busy')
            ->and($seen['reference'])->toBeString()->not->toBe('')
            ->and($seen['commands'])->toBe([])
            ->and($seen['exists'])->toBeTrue();

        // The issuance finished on an intact site.
        expect($certificate->fresh()->status)->toBe(CertificateStatus::Active)
            ->and(CertRaceHost::$lineages)->toHaveKey('shop')
            ->and(lockFree('shop'))->toBeTrue();

        // Asked again, the delete removes a lineage that now exists.
        deleteRaceSite($this->application)->assertOk();

        expect(CertRaceHost::$lineages)->toBe([])
            ->and(CertRaceHost::deleted())->toBe(['shop'])
            ->and(Application::find($this->application->id))->toBeNull();
    });

    it('holds the lock through the whole delete: a job arriving mid-delete creates nothing', function () {
        Queue::fake();
        $certificate = pendingCertificate($this->application);
        $during = null;

        // The job is picked up while the delete is removing the site's vhost —
        // after the certificate step, before the row is gone.
        CertRaceHost::$on['sites-available/shop.conf'] = function () use ($certificate, &$during) {
            $before = count(CertRaceHost::$commands);
            runRaceJob((new IssueCertificate($certificate->id))->onQueue('high'));
            $during = array_slice(CertRaceHost::lines(), $before);
        };

        deleteRaceSite($this->application)->assertOk();

        expect($during)->toBe([])
            ->and(CertRaceHost::$lineages)->toBe([]);

        $requeued = null;
        Queue::assertPushed(IssueCertificate::class, function (IssueCertificate $job) use (&$requeued): bool {
            $requeued = $job;

            return $job->requeues === 1;
        });

        CertRaceHost::$commands = [];
        runRaceJob($requeued);

        expect(CertRaceHost::$commands)->toBe([])
            ->and(CertRaceHost::$lineages)->toBe([]);
    });

    it('puts the job back on the queue untouched while a delete holds the lock', function () {
        Queue::fake();
        $certificate = pendingCertificate($this->application);
        $held = Cache::lock('certificate:shop', 60);
        expect($held->get())->toBeTrue();

        try {
            // Dispatched as the panel dispatches it, on the priority queue.
            runRaceJob((new IssueCertificate($certificate->id, 7, 'old-lineage', ['/etc/ssl/certs/x.crt'], 'http://shop.example.com'))->onQueue('high'));
        } finally {
            $held->release();
        }

        // Not one command, not even certbot; the certificate still Pending.
        expect(CertRaceHost::$commands)->toBe([])
            ->and($certificate->fresh()->status)->toBe(CertificateStatus::Pending);

        // A fresh copy, one attempt like the first, carrying everything.
        Queue::assertPushed(IssueCertificate::class, fn (IssueCertificate $job): bool => $job->requeues === 1
            && $job->queue === 'high'
            && $job->tries === 1
            && $job->actorId === 7
            && $job->previousCertName === 'old-lineage'
            && $job->previousFiles === ['/etc/ssl/certs/x.crt']
            && $job->previousUrl === 'http://shop.example.com'
            && $job->delay !== null
            && abs(now()->diffInSeconds($job->delay) - 30) <= 2);
    });

    it('creates nothing when the requeued job runs after the delete finished', function () {
        Queue::fake();
        $certificate = pendingCertificate($this->application);
        $held = Cache::lock('certificate:shop', 60);
        $held->get();
        runRaceJob((new IssueCertificate($certificate->id))->onQueue('high'));
        $held->release();

        $requeued = null;
        Queue::assertPushed(IssueCertificate::class, function (IssueCertificate $job) use (&$requeued): bool {
            $requeued = $job;

            return true;
        });

        deleteRaceSite($this->application)->assertOk();
        CertRaceHost::$commands = [];

        runRaceJob($requeued);

        expect(CertRaceHost::$commands)->toBe([])
            ->and(CertRaceHost::$lineages)->toBe([]);
    });

    it('fails the certificate instead of waiting forever once the requeue budget is spent', function () {
        Queue::fake();
        config(['server.certificates.lock.max_requeues' => 2]);
        $certificate = pendingCertificate($this->application);
        $job = new IssueCertificate($certificate->id);
        $job->requeues = 2;
        $held = Cache::lock('certificate:shop', 60);
        $held->get();

        try {
            runRaceJob($job);
        } finally {
            $held->release();
        }

        expect(CertRaceHost::$commands)->toBe([])
            ->and($certificate->fresh()->status)->toBe(CertificateStatus::Failed)
            ->and($certificate->fresh()->reason)->toBe('unknown');
        Queue::assertNothingPushed();
    });
});

describe('a site deleted some other way while certbot works', function () {
    it('removes the lineage it just made, activates nothing and does not fail', function () {
        $certificate = pendingCertificate($this->application);
        // A path that does not take the lock (a half-made clone or staging
        // copy being discarded), or a holder whose lock lapsed.
        CertRaceHost::$during = fn () => Application::whereKey($this->application->id)->delete();

        runRaceJob(new IssueCertificate($certificate->id));

        expect(CertRaceHost::$lineages)->toBe([])
            ->and(CertRaceHost::deleted())->toBe(['shop'])
            ->and(CertRaceHost::activated())->toBeFalse()
            ->and(ActivityLog::where('action', 'certificate_issued')->count())->toBe(0)
            ->and(lockFree('shop'))->toBeTrue();
    });

    it('leaves the lineage to a new site that took the same name', function () {
        $certificate = pendingCertificate($this->application);
        CertRaceHost::$during = function () {
            Application::whereKey($this->application->id)->delete();
            raceSite('shop', 'new-shop.example.com');
        };

        runRaceJob(new IssueCertificate($certificate->id));

        expect(CertRaceHost::$lineages)->toHaveKey('shop')
            ->and(CertRaceHost::deleted())->toBe([])
            ->and(CertRaceHost::activated())->toBeFalse();
    });

    it('never removes another live site\'s imported lineage of the same name', function () {
        // A site moved from v7 whose certificate lives under a lineage called
        // `shop` although the site itself is `legacy`.
        $other = raceSite('legacy', 'legacy.example.com');
        Certificate::create([
            'application_id' => $other->id, 'type' => CertificateType::LetsEncrypt, 'status' => CertificateStatus::Active,
            'domains' => ['legacy.example.com'],
            'certificate_path' => '/etc/letsencrypt/live/shop/fullchain.pem', 'private_key_path' => '/etc/letsencrypt/live/shop/privkey.pem',
        ]);
        $certificate = pendingCertificate($this->application);
        CertRaceHost::$during = fn () => Application::whereKey($this->application->id)->delete();

        runRaceJob(new IssueCertificate($certificate->id));

        expect(CertRaceHost::deleted())->toBe([])
            ->and(CertRaceHost::$lineages)->toHaveKey('shop');
    });

    it('does the same for a self-signed pair: removed when unowned, kept for a new owner', function (bool $replaced) {
        $certificate = pendingCertificate($this->application, CertificateType::SelfSigned);
        CertRaceHost::$during = function () use ($replaced) {
            Application::whereKey($this->application->id)->delete();

            if ($replaced) {
                raceSite('shop', 'new-shop.example.com');
            }
        };

        runRaceJob(new IssueCertificate($certificate->id));

        $pair = ['/etc/ssl/certs/shop.crt', '/etc/ssl/private/shop.key'];
        expect(array_keys(CertRaceHost::$files))->toEqualCanonicalizing($replaced ? $pair : [])
            ->and(CertRaceHost::activated())->toBeFalse()
            ->and(CertRaceHost::deleted())->toBe([]);
    })->with(['nobody took the name' => false, 'a new site took the name' => true]);
});

describe('which lock a delete takes', function () {
    it('locks the site\'s slug even when its lineage has another name, and revokes that lineage', function () {
        Certificate::create([
            'application_id' => $this->application->id, 'type' => CertificateType::LetsEncrypt, 'status' => CertificateStatus::Active,
            'domains' => ['shop.example.com'],
            'certificate_path' => '/etc/letsencrypt/live/shop.example.com/fullchain.pem', 'private_key_path' => '/etc/letsencrypt/live/shop.example.com/privkey.pem',
        ]);
        CertRaceHost::$lineages = ['shop.example.com' => true];

        // An issuance for this site holds its slug: the delete waits on it.
        $held = Cache::lock('certificate:shop', 60);
        $held->get();
        $busy = deleteRaceSite($this->application);
        $held->release();

        $busy->assertStatus(503)->assertJsonPath('code', 'server_busy');
        expect(CertRaceHost::$commands)->toBe([]);

        deleteRaceSite($this->application)->assertOk();

        expect(CertRaceHost::deleted())->toBe(['shop.example.com'])
            ->and(CertRaceHost::$lineages)->toBe([]);
    });

    it('is never held up by another site\'s certificate', function () {
        raceSite('blog', 'blog.example.com');
        $held = Cache::lock('certificate:blog', 60);
        $held->get();

        try {
            deleteRaceSite($this->application)->assertOk();
        } finally {
            $held->release();
        }
    });
});

describe('normal issuance', function () {
    it('is unchanged, and releases the lock when it is done', function () {
        $certificate = pendingCertificate($this->application);

        runRaceJob(new IssueCertificate($certificate->id));

        expect($certificate->fresh()->status)->toBe(CertificateStatus::Active)
            ->and(CertRaceHost::$lineages)->toBe(['shop' => true])
            ->and(CertRaceHost::deleted())->toBe([])
            ->and(lockFree('shop'))->toBeTrue()
            // The control for every "activates nothing" above: a real
            // activation is seen.
            ->and(CertRaceHost::activated())->toBeTrue();
    });

    it('releases the lock after a certbot failure, which keeps its classification and is not retried', function () {
        $certificate = pendingCertificate($this->application);
        CertRaceHost::$certonlyFails = "Some challenges have failed.\nType: unauthorized\n";

        runRaceJob(new IssueCertificate($certificate->id));

        $certonly = array_filter(CertRaceHost::lines(), fn (string $l): bool => str_contains($l, 'certbot certonly'));
        expect($certificate->fresh()->status)->toBe(CertificateStatus::Failed)
            ->and($certificate->fresh()->reason)->not->toBe('unknown')
            ->and($certonly)->toHaveCount(1)
            ->and(lockFree('shop'))->toBeTrue();
    });

    it('releases the lock when activation throws, and the certificate still ends failed', function () {
        $certificate = pendingCertificate($this->application);
        $installers = Mockery::mock(InstallerManager::class);
        $installers->shouldReceive('syncUrl')->andThrow(new RuntimeException('installer refused'));

        expect(fn () => runRaceJob(new IssueCertificate($certificate->id), $installers))->toThrow(RuntimeException::class);

        expect($certificate->fresh()->status)->toBe(CertificateStatus::Failed)
            ->and(lockFree('shop'))->toBeTrue();
    });
});

describe('certbot\'s own lock', function () {
    it('retries a delete that met another certbot, and removes the lineage', function () {
        activeRaceCertificate($this->application);
        CertRaceHost::$lineages = ['shop' => true];
        CertRaceHost::$busy = ['delete shop'];

        deleteRaceSite($this->application)->assertOk();

        expect(CertRaceHost::deleted())->toBe(['shop', 'shop'])
            ->and(CertRaceHost::$lineages)->toBe([]);
    });

    it('retries an issuance that met another certbot', function () {
        $certificate = pendingCertificate($this->application);
        CertRaceHost::$busy = ['certonly shop'];

        runRaceJob(new IssueCertificate($certificate->id));

        expect($certificate->fresh()->status)->toBe(CertificateStatus::Active)
            ->and(array_filter(CertRaceHost::lines(), fn (string $l): bool => str_contains($l, 'certbot certonly')))->toHaveCount(2);
    });

    it('gives up after the transient budget, as any held lock does', function () {
        activeRaceCertificate($this->application);
        CertRaceHost::$lineages = ['shop' => true];
        CertRaceHost::$busy = array_fill(0, 10, 'delete shop');

        deleteRaceSite($this->application)->assertOk();

        expect(CertRaceHost::deleted())->toHaveCount((int) config('server.transient.attempts'))
            ->and(CertRaceHost::$lineages)->toHaveKey('shop');
    });
});

describe('the lock itself', function () {
    it('is sized to outlast every holder and the requeues to outlast the lock', function () {
        $job = new IssueCertificate(1);
        $ttl = app(CertificateLock::class)->ttl();

        expect($ttl)->toBeGreaterThan($job->timeout)
            ->and($ttl)->toBeGreaterThan(300)
            ->and((int) config('server.certificates.lock.max_requeues') * (int) config('server.certificates.lock.requeue_delay'))->toBeGreaterThanOrEqual($ttl)
            ->and($job->tries)->toBe(1);

        // The connections a panel actually runs its queue on.
        foreach (['redis', 'database'] as $connection) {
            expect($job->timeout)->toBeLessThan((int) config("queue.connections.{$connection}.retry_after"));
        }

        // The configured default, not the 1s these tests use.
        config(['server.certificates.lock.delete_wait' => null]);
        expect((int) (require config_path('server.php'))['certificates']['lock']['delete_wait'])->toBeLessThan(300);
    });

    it('is not released by a holder whose lease ran out and was taken by another', function () {
        config(['server.certificates.lock.ttl' => 1]);
        $locks = app(CertificateLock::class);

        $first = $locks->forIssue('shop');
        expect($first)->not->toBeNull();

        $this->travel(2)->seconds();
        $second = $locks->forIssue('shop');
        expect($second)->not->toBeNull();

        // The first holder finishing late must not free the second's lock.
        $first->release();
        expect($locks->forIssue('shop'))->toBeNull();

        $second->release();
        expect($locks->forIssue('shop'))->not->toBeNull();
    });
});

function activeRaceCertificate(Application $application): Certificate
{
    return Certificate::create([
        'application_id' => $application->id,
        'type' => CertificateType::LetsEncrypt,
        'status' => CertificateStatus::Active,
        'domains' => [$application->domain],
        'certificate_path' => "/etc/letsencrypt/live/{$application->slug}/fullchain.pem",
        'private_key_path' => "/etc/letsencrypt/live/{$application->slug}/privkey.pem",
    ]);
}
