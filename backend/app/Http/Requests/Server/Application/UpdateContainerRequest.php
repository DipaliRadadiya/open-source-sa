<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\ContainerMemoryLimit;
use App\Rules\ContainerMountPath;
use App\Rules\ExistingDockerNetwork;
use App\Rules\ExistingDockerVolume;
use App\Rules\SingleLine;
use App\Rules\WithinHostCpus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The structured fields of a container site.
 *
 * Its own request, not fields on `UpdateApplicationRequest`: applying these rewrites the
 * compose file and recreates the container, so it needs a throttle and a real pass/fail, not
 * the plain-record semantics of `PUT /applications/{id}`.
 *
 * `image` is not editable here on purpose: changing it pulls, and a bad reference fails after
 * the old container is gone, so it belongs with the deploy path.
 */
class UpdateContainerRequest extends FormRequest
{
    /**
     * The fields the compose file decides for a site that runs its own.
     *
     * Not `memory_limit`, `cpu_limit` or `registry_id`: the panel's compose override applies
     * the limits to the file's services, and the registry credential is used for the pull
     * whatever the file says.
     */
    private const COMPOSE_OWNED_FIELDS = ['container_port', 'docker_network', 'volume_mounts'];

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->canManage('app_container')) {
            return false;
        }

        // Pointing the site at a stored registry credential needs `registry` (view), as at
        // create (`StoreApplicationRequest`); otherwise its id is an oracle for somebody else's
        // credential. Only a CHANGE: the form sends the current registry back on every save.
        $registry = $this->input('registry_id');

        // Nothing chosen, or not an id at all (the rules refuse the latter).
        if ($registry === null || $registry === '' || ! is_scalar($registry)) {
            return true;
        }

        $current = $this->route('application')?->registry_id;

        return (string) $registry === (string) $current || $user->canView('registry');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            // The port inside the container that nginx proxies to. Not `app_port`, the host-side
            // port the panel allocated: conflating them publishes a container on a port another
            // site holds.
            // Optional, never null: null means "read it from the image", which only the create
            // path can do. Leave the key out to keep the current port.
            'container_port' => ['sometimes', 'required', 'integer', 'between:1,65535'],

            // A ceiling, never absent: null falls back to the configured default at render time.
            'memory_limit' => ['sometimes', 'nullable', 'string', 'max:20', new ContainerMemoryLimit, new SingleLine],

            // A CPU quota in cores ('1', '1.5', '0.5'). Unlike memory, null means **no limit**,
            // not a fallback: a default would cap every existing container site on its next
            // deploy. Bounded by the host's core count because Docker refuses an
            // over-provisioned quota at `compose up`.
            'cpu_limit' => ['sometimes', 'nullable', 'string', 'max:16', new WithinHostCpus, new SingleLine],

            // Null means the site's own network again. The rule gets the application because
            // "may this site join it" depends on the site: its own `sv-app-<id>_*` networks yes,
            // another site's no, and the one it is on now is kept.
            'docker_network' => ['sometimes', 'nullable', 'string', 'max:255', new ExistingDockerNetwork($this->route('application'))],

            // When the network changes, whether the one the site was created on
            // (`<project>_default`) is removed once the move is up. Default true. Not a column:
            // the controller hands it to the action separately.
            'remove_previous_network' => ['sometimes', 'boolean'],

            // Which stored credential pulls this site's image. Editable although `image` is not:
            // a token expires while the image reference stays right. Null means pull anonymously.
            'registry_id' => ['sometimes', 'nullable', 'integer', 'exists:registries,id'],

            // The volumes this site mounts; each entry needs BOTH halves.
            'volume_mounts' => ['sometimes', 'nullable', 'array', 'max:20'],
            'volume_mounts.*.volume' => ['required', 'string', 'max:255', new ExistingDockerVolume],
            'volume_mounts.*.path' => [
                'required',
                'string',
                'max:255',
                // Absolute, and one line of plain characters: the value is written into the
                // compose file as YAML, where a newline becomes a new key (`privileged: true`).
                new SingleLine,
                'regex:'.ContainerMountPath::PATTERN,
                // No traversal: it is a path inside the container, but it still reaches a
                // compose file and must stay reviewable.
                'not_regex:/(^|\/)\.\.(\/|$)/',
                // This site's own mount, not a constant: `/app` is the site's directory on older
                // sites and a valid volume path (Gotify's `/app/data`) on newer ones.
                new ContainerMountPath($this->route('application')?->siteMountPath()),
            ],
        ];

        // A site on its own compose file takes none of these three from the row: they are
        // dropped here (an unchanged echo is ignored) and a changed value is refused in `after()`.
        if ($this->ownsCompose()) {
            foreach (self::COMPOSE_OWNED_FIELDS as $field) {
                unset($rules[$field]);
            }

            unset($rules['volume_mounts.*.volume'], $rules['volume_mounts.*.path']);
        }

        return $rules;
    }

    private function ownsCompose(): bool
    {
        return $this->route('application')?->hasOwnCompose() === true;
    }

    /**
     * Which of the compose-owned fields this request tries to change.
     *
     * "Changed" is judged against the row, since the form sends current values back on save.
     *
     * @return list<string>
     */
    private function changedComposeOwnedFields(): array
    {
        $application = $this->route('application');

        if (! $this->ownsCompose()) {
            return [];
        }

        $changed = [];

        if ($this->exists('container_port')) {
            $sent = $this->input('container_port');
            $same = (is_int($sent) || (is_string($sent) && ctype_digit($sent)))
                && (int) $sent === (int) $application->container_port;

            if (! $same) {
                $changed[] = 'container_port';
            }
        }

        if ($this->exists('docker_network')) {
            $sent = $this->input('docker_network');
            $same = ($sent === null || is_string($sent))
                && (($sent === '' ? null : $sent) === ($application->docker_network ?: null));

            if (! $same) {
                $changed[] = 'docker_network';
            }
        }

        if ($this->exists('volume_mounts')) {
            $sent = $this->input('volume_mounts');

            if ($this->mountKeys($sent ?? []) !== $this->mountKeys((array) ($application->volume_mounts ?? []), true)) {
                $changed[] = 'volume_mounts';
            }
        }

        return $changed;
    }

    /**
     * A mount list as a sorted list of `volume path` keys, or null when it is not a list of
     * pairs. Order is not a change. Stored entries without both halves are skipped as the
     * renderer skips them; a sent one is a difference.
     *
     * @return list<string>|null
     */
    private function mountKeys(mixed $mounts, bool $stored = false): ?array
    {
        if (! is_array($mounts)) {
            return null;
        }

        $keys = [];

        foreach ($mounts as $mount) {
            $volume = is_array($mount) ? ($mount['volume'] ?? null) : null;
            $path = is_array($mount) ? ($mount['path'] ?? null) : null;

            if (! is_string($volume) || ! is_string($path) || $volume === '' || $path === '') {
                if ($stored) {
                    continue;
                }

                return null;
            }

            $keys[] = $volume."\0".$path;
        }

        sort($keys);

        return $keys;
    }

    /**
     * The checks that need the whole list, not one entry.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->changedComposeOwnedFields() as $field) {
                    $validator->errors()->add($field, __('errors/application.container_fields_owned_by_compose'));
                }
            },
            function (Validator $validator): void {
                // Dropped with the rules above: such a site saves no mounts.
                if ($this->ownsCompose()) {
                    return;
                }

                $mounts = (array) $this->input('volume_mounts', []);
                $seen = [];

                foreach ($mounts as $index => $mount) {
                    $path = rtrim((string) ($mount['path'] ?? ''), '/');

                    if ($path === '') {
                        continue;
                    }

                    // Two mounts at one path: Docker silently discards the first.
                    if (isset($seen[$path])) {
                        $validator->errors()->add(
                            "volume_mounts.{$index}.path",
                            __('validation.docker_mount_duplicate', ['path' => $path]),
                        );

                        continue;
                    }

                    $seen[$path] = true;
                }
            },
        ];
    }
}
