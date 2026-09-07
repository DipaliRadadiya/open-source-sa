<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The deploy's health check waits for a site that is still coming up —
        // four probes over about fourteen seconds. That schedule is the whole
        // point of the feature and none of the point of a test: left at its
        // real values it added three minutes to the deploy suites alone. Tests
        // that care about the waiting set their own schedule.
        config(['server.verify_backoff_ms' => [0, 0, 0, 0]]);
    }
}
