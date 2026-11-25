<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Models\Worker;
use App\Rules\AvailablePort;
use App\Rules\StartCommand;
use App\Services\Applications\ServingProfile;
use App\Services\Applications\SiteTypeManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Only the things that are safe to change while nothing is provisioned. The
 * site type is not editable — a different type is a different application.
 *
 * Neither is the name: it becomes the slug, which names the vhost, PHP-FPM pool and socket, site
 * directory, logs, fail2ban jail and worker units. A rename would move only the vhost and break
 * the site (502/404), so the name is fixed. The slug is never regenerated on rename, see
 * {@see Application::uniqueSlug()} and
 * {@see Worker::uniqueSlug()}.
 *
 * Same shape as {@see UpdateApplicationDomainRequest}, which omits `domain` for its own reasons.
 */
class UpdateApplicationRequest extends FormRequest
{
    /**
     * The most workers one application may ask for.
     *
     * A fixed ceiling, not the host's core count: `MemoryMax` is divided across the workers in a
     * unit, so an unbounded number leaves each one tiny.
     */
    public const MAX_INSTANCES = 16;

    public function authorize(): bool
    {
        return $this->user()?->canManage('application') ?? false;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.prohibited' => __('validation.application_name_immutable'),
            'domain.prohibited' => __('validation.application_domain_use_endpoint'),
            'node_version.prohibited' => __('errors/node.change_use_endpoint'),
        ];
    }

    /**
     * Clustering needs a script PM2 can fork, and the start command may be
     * changing in this same request.
     *
     * Checked here, not in a rule on `process_instances`, because it depends on another field (or
     * the stored value). Refused, not ignored: PM2 given something it cannot fork runs one process.
     */
    public function after(): array
    {
        return [
            // Re-rendering a git site as something this server does not serve gets the same
            // refusal as creating it that way.
            function (Validator $validator): void {
                $application = $this->route('application');

                if (! $this->has('rendering_type') || ! $application instanceof Application) {
                    return;
                }

                $manager = app(SiteTypeManager::class);
                $type = $manager->find((string) $application->site_type);

                if ($type === null || count($type->servingProfiles()) < 2) {
                    return;
                }

                $profile = ServingProfile::resolve($type, ['rendering_type' => (string) $this->input('rendering_type')]);

                if (($refusal = $manager->unservedProfile($profile)) !== null) {
                    $validator->errors()->add('rendering_type', $refusal);
                }
            },
            function (Validator $validator): void {
                $instances = (int) $this->input('process_instances', 0);

                if ($instances <= 1) {
                    return;
                }

                $command = $this->has('start_command')
                    ? (string) $this->input('start_command')
                    : (string) $this->route('application')?->start_command;

                $parts = preg_split('/\s+/', trim($command)) ?: [];

                if (basename($parts[0] ?? '') !== 'node' || blank($parts[1] ?? null)) {
                    $validator->errors()->add('process_instances', __('validation.process_instances_entrypoint'));
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Refused outright, never quietly dropped, so callers learn this does not rename.
            // {@see UpdateApplication}
            'name' => ['prohibited'],
            // Use PUT /applications/{application}/node-version (restart and check).
            'node_version' => ['prohibited'],
            // Use the Domains endpoints: they handle domain rows, vhost, certificate, uniqueness
            // and the `app_domain` permission; this only wrote the column.
            'domain' => ['prohibited'],
            // See StoreApplicationRequest: this becomes a path used by root.
            'web_root' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'build_command' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Editable, unlike the site type: the same repository can be rebuilt another way.
            'rendering_type' => ['sometimes', 'string', Rule::in(['php', 'static', 'csr', 'ssr'])],
            // Becomes systemd's ExecStart, which is not a shell — see the rule.
            'start_command' => ['sometimes', 'nullable', 'string', 'max:500', new StartCommand],
            // More than one runs `pm2-runtime` and forks that many workers. Bounded, not `-i max`,
            // which would make every application claim every core.
            'process_instances' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::MAX_INSTANCES],
            // Excludes this application, so an unchanged form does not clash with itself.
            'app_port' => ['sometimes', 'nullable', 'integer', 'between:1024,65535', new AvailablePort($this->route('application'))],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
