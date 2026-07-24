<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*.level' => ['required_with:permissions', 'string'],
            'permissions.*.name' => ['required_with:permissions', 'string'],
            'permissions.*.view' => ['required_with:permissions', 'boolean'],
            'permissions.*.manage' => ['required_with:permissions', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Uniqueness is checked on the normalized slug, not the raw name,
        // so "Support Staff" and "support staff" collide as duplicates
        // regardless of casing. Ignores the role being updated itself.
        $validator->after(function (Validator $validator) {
            if (! $this->filled('name')) {
                return;
            }

            $exists = Role::where('slug', Str::slug($this->input('name')))
                ->where('id', '!=', $this->route('role')->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', __('validation.unique', ['attribute' => 'name']));
            }
        });
    }
}
