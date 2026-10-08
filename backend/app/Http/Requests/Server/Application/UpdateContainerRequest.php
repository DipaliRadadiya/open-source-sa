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
        return [
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

            // Null is a real answer: it means Docker's default bridge.
            'docker_network' => ['sometimes', 'nullable', 'string', 'max:255', new ExistingDockerNetwork],

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
