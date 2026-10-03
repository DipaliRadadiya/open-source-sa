<?php

namespace App\Actions\Server\Docker;

use App\Models\Registry;
use App\Services\ActivityLogger;
use App\Services\Server\Docker\RegistryAuth;
use App\Services\Server\ServerOpsResult;

/**
 * Ask the registry whether the stored credential works.
 *
 * Worth its own endpoint for the reason the storage probe is: without it the
 * first time anyone learns a token is wrong is when a site fails to provision,
 * at which point the failure is attributed to the site.
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
     * Two outcomes worth telling apart, because they have different fixes: a
     * credential the registry refused, and a registry nobody could reach.
     *
     * Measured against `docker login` on 2026-09-29, which words the same two
     * failures three ways depending on what it is talking to:
     *
     *   - Hub, wrong password: `Get "https://registry-1.docker.io/v2/":
     *     unauthorized: incorrect username or password`
     *   - self-hosted, wrong password: `login attempt to http://…/v2/ failed
     *     with status: 401 Unauthorized`
     *   - host that does not resolve: `dial tcp: lookup … no such host`
     *
     * Unreachable is decided FIRST and by its own needles rather than by
     * elimination. A DNS failure with `401` nowhere in it would otherwise fall
     * through to "credentials", telling somebody to rotate a token because their
     * registry hostname has a typo.
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

        // Neither pattern matched, so say so rather than picking the likelier of
        // the two. A wrong category here is a user changing the thing that was
        // never broken, which is the same rule the provisioning classifier
        // states about itself.
        return 'unknown';
    }
}
