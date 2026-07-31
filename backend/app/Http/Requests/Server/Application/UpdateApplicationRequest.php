<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\StartCommand;
use Illuminate\Foundation\Http\FormRequest;

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
            'name' => ['sometimes', 'string', 'max:255'],
            'domain' => ['sometimes', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            // See StoreApplicationRequest: this becomes a path used by root.
            'web_root' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'build_command' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Becomes systemd's ExecStart, which is not a shell — see the rule.
            'start_command' => ['sometimes', 'nullable', 'string', 'max:500', new StartCommand],
            'app_port' => ['sometimes', 'nullable', 'integer', 'between:1024,65535'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
