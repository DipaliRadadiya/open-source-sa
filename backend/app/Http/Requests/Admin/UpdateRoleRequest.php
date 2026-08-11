<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccessLevel;
use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            // Either one three-way `access`, or the original pair. The pair
            // stays accepted so nothing already sending it breaks, but a form
            // that sends `access` cannot express "write without read" at all —
            // the combination the resolver used to silently rewrite.
            'permissions.*.access' => ['sometimes', 'string', Rule::in(AccessLevel::cases())],
            'permissions.*.view' => ['required_without:permissions.*.access', 'boolean'],
            'permissions.*.manage' => ['required_without:permissions.*.access', 'boolean'],
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
