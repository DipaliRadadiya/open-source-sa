<?php

namespace App\Http\Requests\Admin;

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
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', Rule::in([10, 20, 50, 100])],
        ];
    }
}
