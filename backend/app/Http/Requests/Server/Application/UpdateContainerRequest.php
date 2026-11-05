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
 * Its own request rather than fields on `UpdateApplicationRequest`, for the
 * reason the routes file gives for web-root and site-type: applying these is a
 * server mutation. It rewrites the compose file and recreates the container,
 * so it needs a throttle and a real pass/fail — not the plain-record semantics
 * of `PUT /applications/{id}`, which would advertise freely editable fields
 * and then honour them only on the next unrelated deploy.
 *
 * `image` is not editable here on purpose. Changing it is a different
 * operation — it pulls, and a bad reference fails after the old container is
 * already gone — and it belongs with the deploy path rather than with a form
 * that saves settings.
 */
class UpdateContainerRequest extends FormRequest
{
    /**
     * The fields the compose file decides for a site that runs its own.
     *
     * Not `memory_limit`, `cpu_limit` or `registry_id`: the panel's compose
     * override applies the limits to the file's services, and the registry
     * credential is used for the pull whatever the file says.
     */
    private const COMPOSE_OWNED_FIELDS = ['container_port', 'docker_network', 'volume_mounts'];

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->canManage('app_container')) {
            return false;
        }

        // Pointing the site at a stored registry credential needs what
        // choosing one at create needs (`StoreApplicationRequest`, DS-08):
        // `registry` (view) — otherwise its id is an oracle for somebody
        // else's credential (DS-12). Only a CHANGE: the form sends the site's
        // current registry back on every save, and keeping the credential the
        // site already pulls with is no new power.
        $registry = $this->input('registry_id');

        // Nothing chosen, or not an id at all — the rules refuse the latter.
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
            // The port inside the container, which is what nginx is proxied
            // to. Not `app_port`: that is the host-side port the panel
            // allocated, and conflating the two publishes a container on a
            // port another site already holds.
            //
            // Optional, never null (DS-09). Since DS-03 null means "read it from
            // the image", which only the create path can do; here it was saved,
            // rendered as port 80, and the site proxied to a port nothing in
            // the image listens on. Leave the key out to keep the current port.
            'container_port' => ['sometimes', 'required', 'integer', 'between:1,65535'],

            // A ceiling, never absent — null here falls back to the configured
            // default at render time rather than to no limit at all.
            //
            // The format moved into a rule of its own once databases needed the
            // same field: `regex` fails with "format is invalid", and the mistake
            // people make is the unit rather than the shape.
            'memory_limit' => ['sometimes', 'nullable', 'string', 'max:20', new ContainerMemoryLimit, new SingleLine],

            // A CPU quota in cores — '1', '1.5', '0.5'. Unlike memory, null means
            // **no limit at all** and not a configured fallback, which is the one
            // asymmetry on this form worth knowing about. Giving CPU a default
            // would cap every container site already on the box the next time it
            // deployed, which is a behaviour change arriving through a feature
            // nobody turned on.
            //
            // Bounded by the host's core count because Docker refuses an
            // over-provisioned quota at `compose up` — so without the rule this
            // field saves, fails to apply, and leaves the panel showing a limit the
            // container does not have.
            'cpu_limit' => ['sometimes', 'nullable', 'string', 'max:16', new WithinHostCpus, new SingleLine],

            // Null is a real answer: it means the site's own network again.
            // The application goes to the rule because "may this site join
            // it" depends on which site: its own `sv-app-<id>_*` networks yes,
            // another site's no (RC-28), and the one it is on now is kept.
            'docker_network' => ['sometimes', 'nullable', 'string', 'max:255', new ExistingDockerNetwork($this->route('application'))],

            // When the network changes, whether the network the site was created on
            // (`<project>_default`) is removed once the move has come up. Default
            // true, today's behaviour — the user can keep it (Dipali, 2026-10-30).
            // Not a column: the controller hands it to the action separately.
            'remove_previous_network' => ['sometimes', 'boolean'],

            // Which stored credential pulls this site's image. Editable here even
            // though `image` is not, and the asymmetry is deliberate: a token
            // expires or gets rotated while the image reference stays exactly
            // right, so a site whose registry cannot be changed is a site that
            // has to be rebuilt to fix a password.
            //
            // Null is a real answer here too — it means pull anonymously, which
            // is correct the moment an image is made public.
            'registry_id' => ['sometimes', 'nullable', 'integer', 'exists:registries,id'],

            // The volumes this site mounts. A list, unlike the network, and each
            // entry needs BOTH halves: a volume name is not actionable without
            // the path it mounts at inside the container.
            'volume_mounts' => ['sometimes', 'nullable', 'array', 'max:20'],
            'volume_mounts.*.volume' => ['required', 'string', 'max:255', new ExistingDockerVolume],
            'volume_mounts.*.path' => [
                'required',
                'string',
                'max:255',
                // Absolute, because a relative mount target is not a path
                // Docker will accept and the error it gives says so badly —
                // and one line of plain characters, because the value is
                // written into the compose file as YAML: a newline in it was a
                // new key (`privileged: true`, a bind of `/`).
                new SingleLine,
                'regex:'.ContainerMountPath::PATTERN,
                // No traversal. The value is a path inside the container rather
                // than on the host, so this is not the same hole a bind mount
                // would be — but it still reaches a compose file, and a target
                // nobody can predict is a target nobody can review.
                'not_regex:/(^|\/)\.\.(\/|$)/',
                // This site's own mount, not a constant: `/app` is the site's
                // directory on a site created before 2026-10-07 and a perfectly
                // good volume path (Gotify's `/app/data`) on one created since.
                new ContainerMountPath($this->route('application')?->siteMountPath()),
            ],
        ];

        // A site that runs its own compose file takes none of these three from
        // the row, so there is nothing to validate and nothing to save: they are
        // dropped here (an unchanged echo is then simply ignored) and a changed
        // value is refused in `after()`.
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
     * "Changed" is judged against the row, because the form sends the current
     * value of a field back on a save and that must stay harmless.
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
     * A mount list as a sorted list of `volume path` keys, or null when it is not
     * a list of pairs. Order is not a change: the file decides what is mounted.
     * Stored entries without both halves are skipped the way the renderer skips
     * them; a sent one is a difference.
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
                // Dropped with the rules above: a site on its own compose file
                // saves no mounts, so two at one path is not a mistake it can make.
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

                    // Two mounts at one path: Docker takes the last and
                    // discards the first silently, so a site would be missing a
                    // volume it is configured to have and nothing would say so.
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
