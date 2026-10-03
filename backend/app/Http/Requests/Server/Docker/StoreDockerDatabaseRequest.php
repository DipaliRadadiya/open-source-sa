<?php

namespace App\Http\Requests\Server\Docker;

use App\Rules\ContainerMemoryLimit;
use App\Rules\ExistingDockerNetwork;
use App\Rules\NewDockerName;
use App\Rules\SingleLine;
use App\Rules\WithinHostCpus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDockerDatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('docker') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The name is also the network alias other containers resolve, so it
            // has to be a name Docker accepts — `NewDockerName` is the same rule
            // the network and volume forms use, and it refuses one that already
            // exists as a Docker object.
            'name' => [
                'required', 'string', 'max:63',
                Rule::unique('docker_databases', 'name'),
                new NewDockerName('database'),
            ],

            // Checked against the CATALOG, not a hardcoded list: engines live in
            // config so an operator can remove one, and a rule that disagreed
            // with the catalog would accept an engine nothing can render.
            'engine' => ['required', 'string', Rule::in(array_keys((array) config('server.docker_databases.engines')))],

            'version' => ['required', 'string', 'max:32'],

            // Null is a real answer: a database only ever reached over its
            // loopback port needs no network at all.
            'docker_network' => ['nullable', 'string', 'max:255', new ExistingDockerNetwork],

            // The instance's size, and the reason these are here rather than read
            // from config: before this, every engine on the box got the same
            // server-wide 512m — a Redis cache and the primary Postgres sized
            // identically. An engine's own buffers are sized from what it can see,
            // so this is the field that decides how the database performs.
            //
            // Both nullable, both falling back to the configured default at render
            // time. CPU null means no quota at all, as it does for a site.
            'cpu_limit' => ['nullable', 'string', 'max:16', new WithinHostCpus, new SingleLine],
            'memory_limit' => ['nullable', 'string', 'max:20', new ContainerMemoryLimit, new SingleLine],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // The version has to belong to the engine that was chosen. Checked
                // here rather than with a rule, because the valid set depends on
                // another field — and accepting `postgres` with MySQL's `8.4` would
                // resolve to no image and fail minutes later at `docker pull`.
                $versions = (array) config('server.docker_databases.engines.'.$this->input('engine').'.versions');

                if (! array_key_exists((string) $this->input('version'), $versions)) {
                    $validator->errors()->add('version', __('errors/docker.database_version_unknown', [
                        'engine' => (string) $this->input('engine'),
                    ]));
                }
            },
        ];
    }
}
