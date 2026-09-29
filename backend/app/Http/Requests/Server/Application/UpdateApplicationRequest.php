<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Models\Worker;
use App\Rules\AvailablePort;
use App\Rules\StartCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the things that are safe to change while nothing is provisioned. The
 * site type is not editable — a different type is a different application.
 *
 * **Neither is the name.** It becomes the slug, and the slug is the filename
 * of six things: the vhost, the PHP-FPM pool and its socket, the site's
 * directory, its logs, its fail2ban jail and its worker units. A rename moved
 * exactly one of them — the vhost — so the site came back pointing at a
 * socket no pool listens on (502) and a directory that had just been created
 * empty beside its real one (404, files orphaned). Measured on a test server,
 * not reasoned about.
 *
 * Moving all six atomically under a live site is a great deal of machinery for
 * a label, so the label is fixed instead. That is also what
 * {@see Application::uniqueSlug()} has always claimed — stored
 * "so the panel can always address the file it wrote, including when the name
 * has since changed" — and what {@see Worker::uniqueSlug()}
 * states outright: "Never regenerated on rename."
 *
 * Same shape as {@see UpdateApplicationDomainRequest}, which omits `domain`
 * for its own reasons.
 */
class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('application') ?? false;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.prohibited' => __('validation.application_name_immutable')];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Refused outright, never quietly dropped, so a caller who was
            // told this endpoint renames a site finds out that it does not.
            // {@see UpdateApplication} for what a rename used to do.
            'name' => ['prohibited'],
            'domain' => ['sometimes', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            // See StoreApplicationRequest: this becomes a path used by root.
            'web_root' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'build_command' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Rebuilding the same repository a different way is an ordinary
            // change of mind, so this is editable — unlike the site type.
            'rendering_type' => ['sometimes', 'string', Rule::in(['php', 'static', 'csr', 'ssr'])],
            // Becomes systemd's ExecStart, which is not a shell — see the rule.
            'start_command' => ['sometimes', 'nullable', 'string', 'max:500', new StartCommand],
            // Excluding this application, so saving an unchanged form does
            // not report the port as taken by itself.
            'app_port' => ['sometimes', 'nullable', 'integer', 'between:1024,65535', new AvailablePort($this->route('application'))],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
