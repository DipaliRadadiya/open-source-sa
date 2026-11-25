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
            // The name is also the network alias other containers resolve, so it must be a name
            // Docker accepts and one that does not already exist as a Docker object.
            'name' => [
                'required', 'string', 'max:63',
                Rule::unique('docker_databases', 'name'),
                new NewDockerName('database'),
            ],

            // Checked against the catalog in config, not a hardcoded list, so it cannot accept an
            // engine nothing can render.
            'engine' => ['required', 'string', Rule::in(array_keys((array) config('server.docker_databases.engines')))],

            'version' => ['required', 'string', 'max:32'],

            // Null is valid: a database reached only over its loopback port needs no network.
            // Never a site's private network: a database belongs to no site, so every
            // `sv-app-<id>_*` network is somebody else's.
            'docker_network' => ['nullable', 'string', 'max:255', new ExistingDockerNetwork],

            // The instance's size, per database rather than one server-wide default. Both are
            // nullable and fall back to the configured default at render time; CPU null means
            // no quota, as for a site.
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

                // The version must belong to the chosen engine (the valid set depends on another
                // field); otherwise it fails minutes later at `docker pull`.
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
