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
            // 32, not 63: MySQL 8 refuses a longer user name outright with
            // "ERROR 1470 ... is too long for user name". 63 is the *database*
            // name limit; letting it through here turns a field the user can
            // fix into a raw engine error from the middle of provisioning.
            'username' => [
                'required', 'string', 'regex:/^[A-Za-z0-9_]{1,32}$/',
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
