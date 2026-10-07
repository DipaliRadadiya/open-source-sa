<?php

namespace App\Services\Server\Docker\Images;

use App\Models\Registry;
use App\Rules\ContainerMountPath;

/**
 * What a simple-mode Docker site takes from its image when it is created (DS-03).
 *
 * - **The container port**, when none was typed: the image's EXPOSE, chosen
 *   the way {@see ImageInspector} suggests. An image that declares none is
 *   refused with a question — there is no silent 80 any more.
 * - **A warning**, when the typed port is not one the image declares. Accepted,
 *   because some images listen on a port they never EXPOSE, but said.
 * - **Its VOLUMEs**, as the site's volumes, when the request named none at all.
 *   `volume_mounts: []` means "none" and is respected.
 *
 * Read from the registry, never pulled. A registry that cannot be asked only
 * matters when the port is missing; otherwise the site is created as typed.
 */
class CreateDefaults
{
    public function __construct(private ImageInspector $inspector) {}

    /**
     * @param  array<string, mixed>  $input  the create request
     * @return array{container_port: int|null, volume_mounts: list<array{path: string}>|null, warnings: list<string>, error: string|null}
     */
    public function resolve(array $input): array
    {
        $answer = ['container_port' => null, 'volume_mounts' => null, 'warnings' => [], 'error' => null];

        $port = filled($input['container_port'] ?? null) ? (int) $input['container_port'] : null;
        $wantsVolumes = ! array_key_exists('volume_mounts', $input) && blank($input['volume_new'] ?? null);

        $facts = $this->facts($input);

        if ($port === null) {
            $answer['error'] = match (true) {
                $facts === false => __('application.docker_create.image_unreadable'),
                $facts === null => __('application.docker_create.port_required'),
                ! ($facts['found'] ?? false) => __('application.docker_create.image_not_found'),
                ($facts['suggested_port'] ?? null) === null => __('application.docker_create.port_required'),
                default => null,
            };

            if ($answer['error'] !== null) {
                return $answer;
            }

            $answer['container_port'] = (int) $facts['suggested_port'];
        } elseif (is_array($facts) && ($facts['found'] ?? false)) {
            $declared = array_map('intval', (array) ($facts['exposed_ports'] ?? []));

            if ($declared !== [] && ! in_array($port, $declared, true)) {
                $answer['warnings'][] = __('application.docker_create.port_mismatch', [
                    'port' => $port,
                    'image_ports' => implode(', ', $declared),
                ]);
            }
        }

        if ($wantsVolumes && is_array($facts) && ($facts['found'] ?? false)) {
            $answer['volume_mounts'] = $this->volumes((array) ($facts['volumes'] ?? []));
        }

        return $answer;
    }

    /**
     * The inspect answer; null when inspection is off or the reference does not
     * parse, false when the registry could not be asked.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|false|null
     */
    private function facts(array $input): array|false|null
    {
        if (! config('server.docker.images.inspect_on_create')) {
            return null;
        }

        $image = ImageReference::parse((string) ($input['image'] ?? ''));

        if ($image === null) {
            return null;
        }

        $credential = filled($input['registry_id'] ?? null) ? Registry::find((int) $input['registry_id']) : null;

        try {
            return $this->inspector->inspect($image, $credential);
        } catch (ImageLookupException) {
            return false;
        }
    }

    /**
     * The image's VOLUME paths a site can mount — the same paths the Container
     * card would accept. One it would refuse (the site mount, a reserved
     * path) is left to Docker's anonymous volume, as before.
     *
     * @param  array<int, mixed>  $paths
     * @return list<array{path: string}>
     */
    private function volumes(array $paths): array
    {
        $rule = new ContainerMountPath;
        $mounts = [];

        foreach ($paths as $path) {
            $path = rtrim((string) $path, '/');

            if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, '..')) {
                continue;
            }

            $refused = false;
            $rule->validate('volume_mounts', $path, function () use (&$refused): void {
                $refused = true;
            });

            if (! $refused) {
                $mounts[] = ['path' => $path];
            }
        }

        return array_slice($mounts, 0, 20);
    }
}
