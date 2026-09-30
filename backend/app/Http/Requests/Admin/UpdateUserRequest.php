<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
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
            'username' => ['required', 'string', 'alpha_dash', 'max:255', Rule::unique('users', 'username')->ignore($this->route('user'))],
            'is_admin' => ['required', 'boolean'],
        ];
    }

    /**
     * Never leave the panel without an administrator.
     *
     * Deleting your own account is already refused, so deletion can never
     * remove the last one — but demotion could: an only administrator could
     * clear their own flag, and with registration closed nobody could reach
     * the admin area again short of editing the database (found in code
     * review 2026-09-29).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');

                if (! $target instanceof User || ! $target->is_admin || $this->boolean('is_admin')) {
                    return;
                }

                $others = User::query()
                    ->where('is_admin', true)
                    ->where('is_system', false)
                    ->whereKeyNot($target->id)
                    ->exists();

                if (! $others) {
                    $validator->errors()->add('is_admin', __('user.cannot_remove_last_admin'));
                }
            },
        ];
    }
}
