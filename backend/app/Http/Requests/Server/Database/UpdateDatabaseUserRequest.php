<?php

namespace App\Http\Requests\Server\Database;

use App\Rules\SupportsRemoteDatabaseUsers;
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
            'connection_preference' => [
                'sometimes', Rule::in(['localhost', 'remote', 'anywhere']),
                new SupportsRemoteDatabaseUsers($this->route('database')?->engine),
            ],
            // The caller's agreement to a cluster restart, needed only by
            // PostgreSQL and only when it is still bound to loopback.
            // Absent means "not agreed", which is a 409 rather than a
            // silent restart of somebody's database.
            'restart_cluster' => ['sometimes', 'boolean'],
            'host' => [
                Rule::requiredIf(fn () => $this->input('connection_preference') === 'remote'),
                'nullable', 'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/',
            ],
            'password' => ['sometimes', 'string', 'min:8', 'max:255'],
        ];
    }
}
