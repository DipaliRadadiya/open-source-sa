<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\ContainerMountPath;
use App\Rules\ExistingDockerNetwork;
use App\Rules\ExistingDockerVolume;
use App\Rules\SingleLine;
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
        return $this->user()?->canManage('application') ?? false;
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
            'container_port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],

            // A ceiling, never absent — null here falls back to the configured
            // default at render time rather than to no limit at all.
            'memory_limit' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d+(b|k|m|g)?$/i', new SingleLine],

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
                // Docker will accept and the error it gives says so badly.
                'regex:/^\//',
                // No traversal. The value is a path inside the container rather
                // than on the host, so this is not the same hole a bind mount
                // would be — but it still reaches a compose file, and a target
                // nobody can predict is a target nobody can review.
                'not_regex:/(^|\/)\.\.(\/|$)/',
                new ContainerMountPath,
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
