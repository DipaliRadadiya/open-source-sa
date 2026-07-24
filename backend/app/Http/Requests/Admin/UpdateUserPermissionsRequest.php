<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserPermissionsRequest extends FormRequest
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
            'permissions' => ['required', 'array'],
            // name is only unique per level (a level can share names with
            // another level, e.g. "logs" under both server and
            // application) — both are required to identify the exact
            // permission; the Action verifies the (name, level) pair
            // actually exists rather than validating it here, since a
            // wildcard `exists` rule can't correlate two sibling fields
            // per array item.
            'permissions.*.level' => ['required', 'string'],
            'permissions.*.name' => ['required', 'string'],
            'permissions.*.view' => ['required', 'boolean'],
            'permissions.*.manage' => ['required', 'boolean'],
        ];
    }
}
