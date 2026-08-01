<?php

namespace App\Actions\Server\Application;

use App\Enums\DomainType;
use App\Models\ApplicationDomain;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

/**
 * Detach a name from an application.
 *
 * The primary is refused rather than handled: removing it would leave the site
 * with no canonical name, no vhost filename and no log paths. Promoting
 * another name first is a deliberate act with its own consequences (a CMS
 * stores its URL), so it is a separate decision rather than a side effect of
 * this one.
 */
class RemoveDomain
{
    public function __construct(
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(ApplicationDomain $domain): void
    {
        if ($domain->type === DomainType::Primary) {
            throw ValidationException::withMessages([
                'domain' => [__('errors/application.primary_domain_not_removable')],
            ]);
        }

        $application = $domain->application;
        $name = $domain->domain;
        $type = $domain->type->value;

        $domain->delete();

        $this->vhost->execute($application->fresh(['domains']));

        $this->activityLogger->log('application.domain_removed', $application, [
            'domain' => $name,
            'type' => $type,
        ]);
    }
}
