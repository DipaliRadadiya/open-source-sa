<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting a site, and optionally the things that outlive it: its files, databases, Docker
 * resources and system user.
 *
 * Every flag defaults to false and has to be asked for: deleting a panel record must not
 * silently destroy someone's code or data. Each flag is validated, so `remove_files=maybe` is a
 * 422, not a silent "keep".
 */
class DestroyApplicationRequest extends FormRequest
{
    /**
     * The route already requires `application,manage`; this adds the permission each extra
     * flag needs.
     *
     * Dropping a database is a separate destructive act on a separate resource, gated by the
     * `database` permission; without the check, someone who may remove a site could reach
     * every database on the server. Checked here, before anything is removed: a 403 means the
     * site is still there.
     */
    public function authorize(): bool
    {
        // A Docker network or volume is a server-level object with its own permission, as a
        // database is: deleting a site must not reach the volume holding another site's data.
        if ($this->boolean('remove_docker_resources') && ! ($this->user()?->canManage('docker') ?? false)) {
            return false;
        }

        // Same permission. Left out, it defaults to on only for someone who has it
        // ({@see removeDockerImages()}).
        if ($this->boolean('remove_docker_images') && ! ($this->user()?->canManage('docker') ?? false)) {
            return false;
        }

        // Removing the site's Linux account needs the System Users permission.
        if ($this->boolean('remove_system_user') && ! ($this->user()?->canManage('system_user') ?? false)) {
            return false;
        }

        if (! $this->boolean('remove_databases')) {
            return true;
        }

        return $this->user()?->canManage('database') ?? false;
    }

    /**
     * The default 403 "This action is unauthorized" would wrongly describe deleting the site.
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(__(
            match (true) {
                ($this->boolean('remove_docker_resources') || $this->boolean('remove_docker_images')) && ! ($this->user()?->canManage('docker') ?? false) => 'errors/application.docker_removal_not_permitted',
                $this->boolean('remove_system_user') && ! ($this->user()?->canManage('system_user') ?? false) => 'errors/application.system_user_removal_not_permitted',
                default => 'errors/application.database_removal_not_permitted',
            }
        ));
    }

    /**
     * The flags arrive as query parameters (`?remove_files=true`), but Laravel's `boolean` rule
     * rejects `"true"`/`"false"`. Anything `filter_var` cannot read stays untouched and fails
     * `boolean`, so a typo is a 422, not a silent "keep".
     */
    protected function prepareForValidation(): void
    {
        foreach (['remove_files', 'remove_databases', 'remove_docker_resources', 'remove_docker_images', 'remove_system_user'] as $flag) {
            if (! $this->has($flag)) {
                continue;
            }

            $value = filter_var($this->input($flag), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($value !== null) {
                $this->merge([$flag => $value]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `sometimes`: absent is the common case and means false for both.
            'remove_files' => ['sometimes', 'boolean'],
            'remove_databases' => ['sometimes', 'boolean'],
            'remove_docker_resources' => ['sometimes', 'boolean'],
            // A Docker site's images, once nothing else uses them.
            'remove_docker_images' => ['sometimes', 'boolean'],
            'remove_system_user' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Whether to remove a Docker site's images with it. On unless turned off (they are
     * re-downloadable), but only for someone who may manage Docker.
     */
    public function removeDockerImages(): bool
    {
        if ($this->has('remove_docker_images')) {
            return $this->boolean('remove_docker_images');
        }

        return $this->user()?->canManage('docker') ?? false;
    }
}
