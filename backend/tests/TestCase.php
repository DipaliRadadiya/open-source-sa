<?php

namespace Tests;

use App\Services\Server\Backups\Storage\FtpsCertificate;
use App\Support\RemoteHost;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
