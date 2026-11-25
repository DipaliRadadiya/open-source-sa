<?php

namespace App\Http\Requests\Server\Database;

use App\Http\Requests\Server\Database\Concerns\ChecksApplicationPairing;
use App\Models\Application;
use App\Rules\RemoteDatabaseHost;
use App\Rules\SupportsRemoteDatabaseUsers;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDatabaseRequest extends FormRequest
{
    use ChecksApplicationPairing;

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
            // Identifiers can't be parameterised in DDL: the strict regex IS the injection guard.
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
            // 32 is MySQL 8's hard limit on a user name (see StoreDatabaseUserRequest).
            'create_user.username' => [
                'required_with:create_user', 'string', 'regex:/^[A-Za-z0-9_]{1,32}$/',
                Rule::notIn((array) config('server.databases.system_users', [])),
                // PostgreSQL reserves the prefix and refuses CREATE ROLE.
                'not_regex:/^pg_/i',
            ],
            'create_user.password' => ['nullable', 'string', 'min:8', 'max:255'],
            'create_user.connection_preference' => [
                'nullable', Rule::in(['localhost', 'remote', 'anywhere']),
                new SupportsRemoteDatabaseUsers((string) $this->input('engine')),
            ],
            // Agreement to a cluster restart, needed only by PostgreSQL while it is bound to
            // loopback. Absent means "not agreed": a 409, never a silent restart.
            'create_user.restart_cluster' => ['sometimes', 'boolean'],
            'create_user.host' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('create_user.connection_preference') === 'remote'),
                new RemoteDatabaseHost($this->input('create_user.connection_preference')),
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

            $this->refuseEngineNotAnswering($validator);

            // The attach screen's rules, for a database made for an application.
            $application = $this->input('application_id') ? Application::find($this->input('application_id')) : null;

            if ($application !== null) {
                $this->refuseSecondDatabase($validator, $application);
                $this->refuseUnusableEngine($validator, $application, (string) $this->input('engine'));
            }
        });
    }

    /**
     * Refuse an engine that does not answer on this server, before any SQL.
     *
     * The API would otherwise accept any configured engine and fail with a 500. One
     * `SELECT VERSION()` on the requested engine only, the same probe as the engines list.
     */
    private function refuseEngineNotAnswering(Validator $validator): void
    {
        $engine = (string) $this->input('engine');

        if ($validator->errors()->has('engine') || ! in_array($engine, app(DatabaseManager::class)->engineNames(), true)) {
            return;
        }

        if (app(DatabaseManager::class)->engine($engine)->version() !== null) {
            return;
        }

        $validator->errors()->add('engine', __('errors/database.engine_not_running', [
            'engine' => (string) config("server.databases.engines.{$engine}.label", $engine),
        ]));
    }

    /**
     * The charsets the *requested* engine actually understands.
     *
     * Read per engine (a global list accepted `utf8mb4` on MongoDB). A missing or unknown engine
     * yields an empty list, so `Rule::in` rejects every charset.
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
