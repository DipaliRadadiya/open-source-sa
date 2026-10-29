<?php

namespace Tests;

use App\Services\Server\Backups\Storage\FtpsCertificate;
use App\Support\RemoteHost;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application, then refuse to go on if it points at a real
     * database. This runs before setUpTraits(), so RefreshDatabase never gets
     * a connection to wipe (it did, twice: see TestDatabaseGuard).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        try {
            TestDatabaseGuard::check($app);
        } catch (RuntimeException $refused) {
            // End the whole run, not just this test: PHPUnit would report a
            // thrown error and carry on into the next test, and the next.
            fwrite(STDERR, PHP_EOL.$refused->getMessage().PHP_EOL);

            exit(1);
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // No real DNS in tests: a host check resolves names (bug #34), and a
        // lookup per validation would make the suite slow and depend on the
        // network. A test about resolution sets its own answers.
        RemoteHost::resolveUsing(fn (): array => []);

        // No real TLS handshakes either (FTP-01): an FTPS certificate reads as
        // "nothing to record" unless a test says what the server presents.
        $this->app->instance(FtpsCertificate::class, new FtpsCertificate(fn (): ?string => null));

        // The deploy's health check waits for a site that is still coming up —
        // four probes over about fourteen seconds. That schedule is the whole
        // point of the feature and none of the point of a test: left at its
        // real values it added three minutes to the deploy suites alone. Tests
        // that care about the waiting set their own schedule.
        config(['server.verify_backoff_ms' => [0, 0, 0, 0]]);
    }
}
