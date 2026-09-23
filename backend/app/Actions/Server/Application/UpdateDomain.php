<?php

namespace App\Actions\Server\Application;

use App\Enums\DomainType;
use App\Models\ApplicationDomain;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

/**
 * Change what an attached name does, without detaching it.
 *
 * Exists because the only way to fix a mistyped redirect target used to be
 * delete-then-re-add. That is two writes where one was meant, and the gap
 * between them is not harmless: the vhost is re-rendered in between without
 * the name, so the redirect stops answering, and an add that then fails —
 * or is simply forgotten — leaves the name off a site whose certificate still
 * covers it, which is the state that silently kills renewal for every other
 * name on that certificate.
 *
 * The primary is refused for the same reason `RemoveDomain` refuses it: it
 * names the vhost file and both log files, and changing it is
 * `ChangePrimaryDomain`'s job.
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

        // The type after this request, which is not always the type in it: a
        // request changing only `redirect_to` on a row that is already a
        // redirect carries no `type` at all.
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
            // An alias serves the site itself, so a target left behind on one
            // would be a value nothing reads and everything shows.
            'redirect_to' => $type === DomainType::Redirect ? $target : null,
            'redirect_status' => $type === DomainType::Redirect
                ? (int) ($data['redirect_status'] ?? $domain->redirect_status ?? 301)
                : $domain->redirect_status,
        ]);

        // Nothing to write, and nothing to reload. Saving anyway would append
        // an activity-log line saying something changed when it did not.
        if (! $domain->isDirty()) {
            return $domain;
        }

        $domain->save();

        // A redirect is served from its own server block: alias to redirect
        // adds one, redirect to alias removes one, and a changed target
        // rewrites it. Every path here changes the rendered config.
        $this->vhost->execute($domain->application->fresh(['domains']));

        $this->activityLogger->log('application.domain_updated', $domain->application, [
            'domain' => $domain->domain,
            'type' => $domain->type->value,
        ]);

        return $domain->refresh();
    }
}
