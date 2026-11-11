<?php

namespace App\Actions\Server\Application;

use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\V7Leftovers;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns the plain HTTP block into a redirect.
 *
 * Refused without a servable certificate, and that refusal is the whole point
 * of the action existing. Redirecting to HTTPS on a site with no certificate
 * does not degrade — it takes the site off the internet, and it does so for
 * every visitor at once, including the one who just clicked the toggle.
 */
class SetForceHttps
{
    public function __construct(
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
        private V7Leftovers $leftovers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(Certificate $certificate, bool $force): Certificate
    {
        if ($force && ! $certificate->servable()) {
            throw ValidationException::withMessages([
                'force_https' => [__('errors/certificate.force_https_without_certificate')],
            ]);
        }

        $previous = (bool) $certificate->force_https;
        $certificate->update(['force_https' => $force]);
        $application = $certificate->application->fresh(['domains', 'certificate']);

        // v7's own forceHttps.conf (Apache) goes first: it sits at the name v8
        // writes to, and v8 never writes over a file it did not write.
        $moved = $this->leftovers->remove($application, 'force_https');

        try {
            $this->vhost->execute($application);
        } catch (Throwable $e) {
            $this->leftovers->restore($application, $moved);
            $certificate->update(['force_https' => $previous]);

            throw $e;
        }

        $this->activityLogger->log(
            $force ? 'application.force_https_enabled' : 'application.force_https_disabled',
            $certificate->application,
            ['domain' => $certificate->application->domain],
        );

        return $certificate->refresh();
    }
}
