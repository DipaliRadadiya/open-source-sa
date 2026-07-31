<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\PortAllocator;

/**
 * Record an application the user asked for.
 *
 * P1 stops here deliberately: nothing is written to the server, so the app is
 * saved as `pending` and the UI must say "not deployed yet". Provisioning
 * arrives in P2 and will move it through `provisioning` to `active`.
 */
class CreateApplication
{
    public function __construct(
        private SiteTypeManager $siteTypes,
        private ActivityLogger $activityLogger,
        private PortAllocator $ports,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Application
    {
        $type = $this->siteTypes->find((string) $data['site_type']);

        $application = Application::create([
            'site_type' => $type->name(),
            // Derived, never taken from the client. The type decides how it
            // is served — unless the application runs a process of its own, in
            // which case it is served by proxying to that process. Serving the
            // directory instead would publish the source of an app whose
            // routing lives in code.
            'serving_profile' => filled($data['start_command'] ?? null) ? 'node' : $type->servingProfile(),
            'status' => ApplicationStatus::Pending,
            'system_user_id' => $data['system_user_id'],
            'name' => $data['name'],
            'domain' => $data['domain'],
            'php_version' => $data['php_version'] ?? null,
            'node_version' => $data['node_version'] ?? null,
            // Allocated when the app needs a process and the user did not pick
            // one. A port the panel chose is checked against both the database
            // and what is actually listening; a port the user typed is checked
            // the same way at validation.
            'app_port' => $this->port($data),
            // The type's own default, not a bare '/': a framework
            // application served from its root publishes its own source.
            'web_root' => $data['web_root'] ?? $type?->defaultWebRoot() ?? '/',
            'build_command' => $data['build_command'] ?? null,
            'start_command' => $data['start_command'] ?? null,
            'git_account_id' => $data['git_account_id'] ?? null,
            'repository' => $data['repository'] ?? null,
            'repository_url' => $data['repository_url'] ?? null,
            'branch' => $data['branch'] ?? null,
            'settings' => $this->typeSettings($type->fields(), $data),
        ]);

        $this->activityLogger->log('application.created', $application, [
            'name' => $application->name,
            'site_type' => $application->site_type,
        ]);

        // Provisioning is long enough that the request must not wait for it;
        // the client polls the application's status.
        ProvisionApplication::dispatch($application->id);

        return $application->fresh(['systemUser']);
    }

    /**
     * The type-specific answers, taken strictly from the fields the site type
     * declared — so an unexpected key in the payload cannot end up stored.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function typeSettings(array $fields, array $data): array
    {
        $columns = (new Application)->getFillable();
        $settings = [];

        foreach ($fields as $field) {
            $name = (string) $field['name'];

            if (! in_array($name, $columns, true) && array_key_exists($name, $data)) {
                $settings[$name] = $data[$name];
            }
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function port(array $data): ?int
    {
        if (filled($data['app_port'] ?? null)) {
            return (int) $data['app_port'];
        }

        // Only an application that runs something needs a port. Handing one to
        // every PHP site would exhaust the range for no reason.
        return blank($data['start_command'] ?? null) ? null : $this->ports->allocate();
    }
}
