<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDatabaseUserRequest extends FormRequest
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
            // 32 is MySQL 8's hard limit on a user name — see
            // StoreDatabaseUserRequest.
            'username' => [
                'sometimes', 'string', 'regex:/^[A-Za-z0-9_]{1,32}$/',
                Rule::notIn((array) config('server.databases.system_users', [])),
            ],
            'connection_preference' => ['sometimes', Rule::in(['localhost', 'remote', 'anywhere'])],
            'host' => [
                Rule::requiredIf(fn () => $this->input('connection_preference') === 'remote'),
                'nullable', 'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/',
            ],
            'password' => ['sometimes', 'string', 'min:8', 'max:255'],
        ];
    }
}
