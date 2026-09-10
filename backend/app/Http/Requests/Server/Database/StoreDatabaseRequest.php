<?php

namespace App\Http\Requests\Server\Database;

use App\Rules\SupportsRemoteDatabaseUsers;
use App\Services\Server\Databases\DatabaseManager;
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
        $charsets = $this->charsets();
        $allCollations = $charsets === [] ? [] : array_merge(...array_values($charsets));

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
            'create_user.connection_preference' => [
                'nullable', Rule::in(['localhost', 'remote', 'anywhere']),
                new SupportsRemoteDatabaseUsers((string) $this->input('engine')),
            ],
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
                $allowed = (array) ($this->charsets()[$charset] ?? []);
                if (! in_array($collation, $allowed, true)) {
                    $validator->errors()->add('collation', __('errors/database.collation_mismatch'));
                }
            }
        });
    }

    /**
     * The charsets the *requested* engine actually understands.
     *
     * Read per engine rather than from one global list, which accepted
     * `utf8mb4` on a MongoDB database: the rule passed, the value was stored,
     * and the engine had no such concept. An engine that is missing or unknown
     * yields an empty list, so the `Rule::in` below rejects every charset —
     * `engine` is required, and a charset for an engine we cannot name is not
     * a value to wave through.
     *
     * @return array<string, array<int, string>>
     */
    private function charsets(): array
    {
        $engine = (string) $this->input('engine');

        return in_array($engine, app(DatabaseManager::class)->engineNames(), true)
            ? app(DatabaseManager::class)->charsets($engine)
            : [];
    }

    /**
     * @return array<int, string>
     */
    private function systemSchemas(): array
    {
        return app(DatabaseManager::class)->allSystemSchemas();
    }
}
