<?php

namespace App\Actions\Server\Application;

use App\Enums\DomainType;
use App\Models\ApplicationDomain;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

/**
 * Change what an attached name does, without detaching it.
 *
 * Exists so a redirect target can be fixed in one write: delete-then-re-add re-renders the vhost
 * without the name in between, and a failed add leaves the name off a site whose certificate
 * still covers it, which silently kills renewal for every other name on that certificate.
 *
 * The primary is refused, as in `RemoveDomain`: it names the vhost file and both log files, and
 * changing it is `ChangePrimaryDomain`'s job.
 */
class UpdateDomain
{
    public function __construct(
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function execute(ApplicationDomain $domain, array $data): ApplicationDomain
    {
        if ($domain->type === DomainType::Primary) {
            throw ValidationException::withMessages([
                'type' => [__('errors/application.primary_domain_not_editable')],
            ]);
        }

        // The type after this request: a request changing only `redirect_to` carries no `type`.
        $type = DomainType::from((string) ($data['type'] ?? $domain->type->value));

        $target = array_key_exists('redirect_to', $data)
            ? (($data['redirect_to'] === null || $data['redirect_to'] === '') ? null : (string) $data['redirect_to'])
            : $domain->redirect_to;

        if ($type === DomainType::Redirect && $target === null) {
            throw ValidationException::withMessages([
                'redirect_to' => [__('validation.required', ['attribute' => 'redirect to'])],
            ]);
        }

        $domain->fill([
            'type' => $type,
            // An alias serves the site itself, so it keeps no target.
            'redirect_to' => $type === DomainType::Redirect ? $target : null,
            'redirect_status' => $type === DomainType::Redirect
                ? (int) ($data['redirect_status'] ?? $domain->redirect_status ?? 301)
                : $domain->redirect_status,
        ]);

        // Nothing changed: no write, no reload, no activity-log line.
        if (! $domain->isDirty()) {
            return $domain;
        }

        $domain->save();

        // A redirect has its own server block, so every change here alters the rendered config.
        $this->vhost->execute($domain->application->fresh(['domains']));

        $this->activityLogger->log('application.domain_updated', $domain->application, [
            'domain' => $domain->domain,
            'type' => $domain->type->value,
        ]);

        return $domain->refresh();
    }
}
