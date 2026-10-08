<?php

namespace App\Http\Requests\Admin;

use App\Services\ActivityKinds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'filter.action' => ['sometimes', 'string', 'max:255'],
            'filter.type' => ['sometimes', 'string', 'max:255'],
            'filter.scope' => ['sometimes', 'string', Rule::in(array_keys((array) config('activity.scopes', [])))],
            // FS-C15 / OLD-18: what kind of event, and security events only.
            'filter.kind' => ['sometimes', 'string', Rule::in(ActivityKinds::KINDS)],
            'filter.security' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', Rule::in([10, 20, 50, 100])],
        ];
    }
}
