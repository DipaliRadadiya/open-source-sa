<?php

namespace App\Actions\Server\Docker;

use App\Models\Registry;
use App\Services\ActivityLogger;
use App\Services\Server\Docker\RegistryAuth;
use App\Services\Server\ServerOpsResult;

/**
 * Ask the registry whether the stored credential works.
 *
 * Its own endpoint, like the storage probe: otherwise a wrong token is first noticed when a
 * site fails to provision, and the failure is blamed on the site.
 */
class TestRegistry
{
    public function __construct(
        private RegistryAuth $auth,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Registry $registry): Registry
    {
        $result = $this->auth->probe($registry, ['feature' => 'docker', 'registry' => $registry->id]);

        $registry->last_tested_at = now();
        $registry->last_test_success = $result->ok;
        $registry->last_test_error = $result->ok ? null : $this->classify($result);
        $registry->save();

        $this->activityLogger->log('registry.tested', $registry, [
            'name' => $registry->name,
            'registry' => $registry->registry,
            'success' => $registry->last_test_success,
            // The category, never the output: Docker quotes the registry URL and
            // the username back in its refusals.
            'error' => $registry->last_test_error,
        ]);

        return $registry;
    }

    /**
     * Two outcomes with different fixes: a credential the registry refused, and a registry
     * nobody could reach. `docker login` words these differently for Hub (`unauthorized:
     * incorrect username or password`), self-hosted (`401 Unauthorized`) and DNS failures
     * (`no such host`), hence the needles below.
     *
     * Unreachable is decided FIRST, by its own needles: a DNS failure with no `401` in it would
     * otherwise fall through to "credentials" and tell someone to rotate a token over a typo.
     */
    private function classify(ServerOpsResult $result): string
    {
        $output = $result->errorOutput()."\n".$result->output();

        foreach ([
            'no such host',
            'dial tcp',
            'connection refused',
            'i/o timeout',
            'certificate',
            'server gave HTTP response to HTTPS client',
        ] as $needle) {
            if (str_contains($output, $needle)) {
                return 'unreachable';
            }
        }

        foreach (['incorrect username or password', '401 Unauthorized', 'unauthorized'] as $needle) {
            if (str_contains($output, $needle)) {
                return 'invalid_credentials';
            }
        }

        // Neither pattern matched: say so rather than guess. A wrong category sends the user to
        // change something that was never broken.
        return 'unknown';
    }
}
