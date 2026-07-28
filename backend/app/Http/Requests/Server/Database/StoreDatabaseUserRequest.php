<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'required', 'string', 'regex:/^[A-Za-z0-9_]{1,63}$/',
                Rule::notIn((array) config('server.databases.system_users', [])),
            ],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'connection_preference' => ['required', Rule::in(['localhost', 'remote', 'anywhere'])],
            'host' => [
                Rule::requiredIf(fn () => $this->input('connection_preference') === 'remote'),
                'nullable',
                'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/',
            ],
        ];
    }
}
