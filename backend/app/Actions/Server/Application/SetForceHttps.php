<?php

namespace App\Actions\Server\Application;

use App\Models\Certificate;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

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

        $certificate->update(['force_https' => $force]);

        $this->vhost->execute($certificate->application->fresh(['domains', 'certificate']));

        $this->activityLogger->log(
            $force ? 'application.force_https_enabled' : 'application.force_https_disabled',
            $certificate->application,
            ['domain' => $certificate->application->domain],
        );

        return $certificate->refresh();
    }
}
