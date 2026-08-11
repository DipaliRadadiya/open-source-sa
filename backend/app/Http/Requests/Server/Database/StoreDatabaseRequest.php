<?php

namespace App\Http\Requests\Server\Database;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDatabaseRequest extends FormRequest
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
        $charsets = (array) config('server.databases.charsets', []);
        $allCollations = array_merge(...array_values($charsets)) ?: [];

        return [
            // Identifiers can't be parameterised in DDL → the strict regex IS
            // the injection guard.
            'name' => [
                'required', 'string', 'regex:/^[A-Za-z0-9_]{1,63}$/',
                Rule::notIn($this->systemSchemas()),
                Rule::unique('databases', 'name')->where('engine', $this->input('engine')),
            ],
            'engine' => ['required', Rule::in(array_keys((array) config('server.databases.engines', [])))],
            'charset' => ['nullable', 'string', Rule::in(array_keys($charsets))],
            'collation' => ['nullable', 'string', Rule::in($allCollations)],
            'application_id' => ['nullable', 'integer', Rule::exists('applications', 'id')],

            'create_user' => ['nullable', 'array'],
            // 32 is MySQL 8's hard limit on a user name, unlike the 63 allowed
            // for the database name above — see StoreDatabaseUserRequest.
            'create_user.username' => [
                'required_with:create_user', 'string', 'regex:/^[A-Za-z0-9_]{1,32}$/',
                Rule::notIn((array) config('server.databases.system_users', [])),
            ],
            'create_user.password' => ['nullable', 'string', 'min:8', 'max:255'],
            'create_user.connection_preference' => ['nullable', Rule::in(['localhost', 'remote', 'anywhere'])],
            'create_user.host' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('create_user.connection_preference') === 'remote'),
                'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Collation must belong to the chosen charset.
            $charset = $this->input('charset');
            $collation = $this->input('collation');
            if ($charset && $collation) {
                $allowed = (array) config("server.databases.charsets.{$charset}", []);
                if (! in_array($collation, $allowed, true)) {
                    $validator->errors()->add('collation', __('errors/database.collation_mismatch'));
                }
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function systemSchemas(): array
    {
        return array_merge(
            (array) config('server.databases.system_schemas.sql', []),
            (array) config('server.databases.system_schemas.mongo', []),
        );
    }
}
