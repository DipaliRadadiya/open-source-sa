<?php

namespace App\Http\Requests\Server\Application;

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
            'web_root' => ['sometimes', 'nullable', 'string', 'max:255'],
            'build_command' => ['sometimes', 'nullable', 'string', 'max:500'],
            'start_command' => ['sometimes', 'nullable', 'string', 'max:500'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
