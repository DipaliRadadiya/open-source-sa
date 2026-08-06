<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\AvailablePort;
use App\Rules\StartCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the things that are safe to change while nothing is provisioned. The
 * site type is not editable — a different type is a different application.
 */
class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('application') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // See StoreApplicationRequest: the name is the config filename.
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('applications', 'name')->ignore($this->route('application'))],
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
