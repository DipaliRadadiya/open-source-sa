<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\ExistingDockerNetwork;
use App\Rules\SingleLine;
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
        ];
    }
}
