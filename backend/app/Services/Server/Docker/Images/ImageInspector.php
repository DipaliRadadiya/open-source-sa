<?php

namespace App\Services\Server\Docker\Images;

use App\Models\Registry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What an image needs, read from the image itself.
 *
 * The answer to the port trap. "Container port" used to default to 80 and be
 * free text, so users typed a port of their own choosing and nginx proxied to
 * something nothing listened on — a 502 behind a panel that said "Running".
 * The image already says which port it listens on (`EXPOSE`), where it keeps
 * its data (`VOLUME`) and which settings it reads (`ENV`). This reads those
 * from the registry, without pulling, so the form can fill itself in.
 *
 * Everything here is a suggestion with its provenance attached
 * (`port_confidence`), never a fact the panel enforces: an image that EXPOSEs
 * nothing still listens on something, and the user is the one who knows what.
 */
class ImageInspector
{
    /**
     * Ports a web app is most likely to be served on, best first. Used only
     * to choose between several EXPOSEd ports — Heimdall exposes 80 and 443,
     * and the panel terminates TLS itself, so 80 is the one to proxy to.
     */
    private const PREFERRED_PORTS = [80, 8080, 3000, 5000, 8000, 8081, 8888, 9000, 3001, 5230, 8096];

    public function __construct(private RegistryClient $registry) {}

    /**
     * @return array<string, mixed> the DS-02 inspect contract
     *
     * @throws ImageLookupException when the registry could not be asked
     */
    public function inspect(ImageReference $image, ?Registry $credential, ?string $architecture = null): array
    {
        $architecture ??= $this->serverArchitecture();

        if ($credential !== null && ! $image->matches($credential)) {
            // Never sent to a registry it was not saved for.
            $credential = null;
        }

        $cacheKey = 'docker-image-inspect:'.sha1($image->full().'|'.($credential?->id ?? '-').'|'.$architecture);

        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        try {
            $answer = $this->read($image, $credential, $architecture);
        } catch (ImageLookupException $e) {
            if (! $e->isAboutTheImage()) {
                throw $e;
            }

            return $this->notFound($image, $e->reason);
        }

        Cache::put($cacheKey, $answer, now()->addMinutes((int) config('server.docker.images.inspect_cache_minutes', 10)));

        return $answer;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(ImageReference $image, ?Registry $credential, string $architecture): array
    {
        ['manifest' => $manifest, 'digest' => $digest] = $this->registry->manifest($image, $image->manifestReference(), $credential);

        $architectures = [];
        $hasBuild = true;

        if (isset($manifest['manifests']) && is_array($manifest['manifests'])) {
            $platforms = array_values(array_filter(
                $manifest['manifests'],
                // Attestation manifests carry `unknown/unknown`; they are not images.
                fn ($entry): bool => is_array($entry)
                    && ($entry['platform']['os'] ?? null) === 'linux'
                    && ($entry['platform']['architecture'] ?? 'unknown') !== 'unknown',
            ));

            foreach ($platforms as $entry) {
                $arch = (string) $entry['platform']['architecture'];
                $variant = (string) ($entry['platform']['variant'] ?? '');
                $architectures[] = $arch.($variant !== '' && $arch !== 'arm64' ? '/'.$variant : '');
            }

            $chosen = collect($platforms)->first(fn (array $entry): bool => $entry['platform']['architecture'] === $architecture);

            if ($chosen === null) {
                // Still read something, so the user is told what the image is
                // AND that it will not run here, rather than only the latter.
                $hasBuild = false;
                $chosen = $platforms[0] ?? null;
            }

            if ($chosen === null) {
                throw new ImageLookupException(ImageLookupException::TAG_NOT_FOUND, 'no linux image in the index');
            }

            $manifest = $this->registry->manifest($image, (string) $chosen['digest'], $credential)['manifest'];
        }

        $configDigest = (string) ($manifest['config']['digest'] ?? '');

        if ($configDigest === '') {
            // A schema-1 manifest. Hub stopped serving them years ago; anything
            // still answering with one is too old to describe.
            throw new ImageLookupException(ImageLookupException::TAG_NOT_FOUND, 'manifest has no config');
        }

        // The digest is the cache key, and it came from a manifest served by a
        // host the user chose — so it is checked for shape here and the blob is
        // checked against it in `blob()`. Without both, one user's registry
        // could answer with another image's digest and a blob of its own, and
        // everyone inspecting that image for the next hour would read it.
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $configDigest) !== 1) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'manifest config digest is malformed');
        }

        // A config blob is content-addressed, so it can be cached for as long
        // as we like; an hour keeps the cache from growing without bound. Keyed
        // by registry too: content addressing is only as good as the host
        // vouching for it.
        $config = Cache::remember(
            'docker-image-config:'.$image->apiHost().':'.$configDigest,
            now()->addMinutes((int) config('server.docker.images.config_cache_minutes', 60)),
            fn (): array => $this->registry->blob($image, $configDigest, $credential),
        );

        if ($architectures === []) {
            $architectures[] = (string) ($config['architecture'] ?? $architecture);
            $hasBuild = ($config['architecture'] ?? $architecture) === $architecture;
        }

        $size = (int) ($manifest['config']['size'] ?? 0);

        foreach ((array) ($manifest['layers'] ?? []) as $layer) {
            $size += (int) ($layer['size'] ?? 0);
        }

        return $this->describe($image, $digest, $config, $size, array_values(array_unique($architectures)), $hasBuild, $architecture);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $architectures
     * @return array<string, mixed>
     */
    private function describe(ImageReference $image, string $digest, array $config, int $size, array $architectures, bool $hasBuild, string $architecture): array
    {
        $runtime = (array) ($config['config'] ?? []);

        $ports = $this->exposedPorts((array) ($runtime['ExposedPorts'] ?? []));
        [$port, $confidence] = $this->suggestedPort($image, $ports);

        $volumes = array_filter(array_keys((array) ($runtime['Volumes'] ?? [])), 'is_string');
        $known = (array) config('server.docker.images.known_volumes', []);
        $volumes = array_values(array_unique([...$volumes, ...(array) ($known[$image->name()] ?? [])]));
        sort($volumes);

        $env = $this->environment($image, (array) ($runtime['Env'] ?? []));
        $workdir = (string) ($runtime['WorkingDir'] ?? '');

        $warnings = [];
        $required = array_column(array_filter($env, fn (array $item): bool => $item['required']), 'key');
        $empty = array_column(array_filter($env, fn (array $item): bool => ! $item['required'] && $item['default'] === ''), 'key');

        if ($required !== []) {
            $warnings[] = __('docker.image.warning_required_env', ['keys' => implode(', ', $required)]);
        }

        if ($empty !== []) {
            $warnings[] = __('docker.image.warning_empty_env', ['keys' => implode(', ', $empty)]);
        }

        if (! $hasBuild) {
            $warnings[] = __('docker.image.warning_no_build', ['architecture' => 'linux/'.$architecture]);
        }

        if ($size >= (int) config('server.docker.images.large_bytes', 1024 ** 3)) {
            $warnings[] = __('docker.image.warning_large', ['size' => $this->humanSize($size)]);
        }

        if ($confidence === 'none') {
            $warnings[] = __('docker.image.warning_no_port');
        } elseif (count($ports) > 1) {
            $warnings[] = __('docker.image.warning_several_ports', ['ports' => implode(', ', $ports), 'port' => $port]);
        }

        return [
            'image' => $image->full(),
            'digest' => $digest,
            'found' => true,
            'exposed_ports' => $ports,
            'suggested_port' => $port,
            'port_confidence' => $confidence,
            'volumes' => $volumes,
            'suggested_volumes' => $this->suggestedVolumes($image, $volumes),
            'env' => $env,
            'workdir' => $workdir,
            'user' => (string) ($runtime['User'] ?? ''),
            'size_bytes' => $size,
            'architectures' => $architectures,
            'uses_app_dir' => $this->usesAppDir($config, $workdir),
            'warnings' => $warnings,
        ];
    }

    /**
     * TCP ports only: the panel proxies HTTP, and a UDP port is not something
     * nginx can be pointed at.
     *
     * @param  array<string, mixed>  $exposed  keys like `5230/tcp`
     * @return list<int>
     */
    private function exposedPorts(array $exposed): array
    {
        $ports = [];

        foreach (array_keys($exposed) as $key) {
            if (preg_match('#^(\d+)(?:/tcp)?$#', (string) $key, $match) === 1) {
                $port = (int) $match[1];

                if ($port >= 1 && $port <= 65535) {
                    $ports[] = $port;
                }
            }
        }

        $ports = array_values(array_unique($ports));
        sort($ports);

        return $ports;
    }

    /**
     * @param  list<int>  $ports
     * @return array{0: int|null, 1: string}
     */
    private function suggestedPort(ImageReference $image, array $ports): array
    {
        if (count($ports) === 1) {
            return [$ports[0], 'declared'];
        }

        if ($ports !== []) {
            foreach (self::PREFERRED_PORTS as $preferred) {
                if (in_array($preferred, $ports, true)) {
                    return [$preferred, 'declared'];
                }
            }

            // Lowest, except that 443 is never the one to proxy plain HTTP to.
            $plain = array_values(array_diff($ports, [443, 8443]));

            return [$plain[0] ?? $ports[0], 'declared'];
        }

        // Images that listen on a port without declaring it. Keyed by the
        // repository as users write it, without a tag.
        $known = (array) config('server.docker.images.known_ports', []);

        if (isset($known[$image->name()])) {
            return [(int) $known[$image->name()], 'guessed'];
        }

        return [null, 'none'];
    }

    /**
     * `ENV` lines worth showing a user, with the image's defaults.
     *
     * Build plumbing is hidden — `PATH`, `NGINX_VERSION`, checksums. Changing
     * `NGINX_VERSION` does not change the nginx in the image, and changing
     * `PATH` breaks it; neither belongs in a form.
     *
     * **`required` is never inferred.** An image has no way to declare that a
     * setting is mandatory, and an empty `ENV` is not one: measured on
     * 2026-10-07, changedetection.io ships `LOGGER_LEVEL=` and Umami
     * `NODE_OPTIONS=`, and both start fine without them. The create form blocks
     * Deploy on an empty required value, so a guess here would refuse working
     * images. Required comes only from the `required_env` table — settings an
     * image's own documentation says it will not start without, most of which
     * (`POSTGRES_PASSWORD`) are not in the image's `ENV` at all.
     *
     * @param  array<int, mixed>  $lines
     * @return list<array{key: string, default: string, required: bool}>
     */
    private function environment(ImageReference $image, array $lines): array
    {
        $hidden = (array) config('server.docker.images.hidden_env', []);
        $mandatory = (array) (config('server.docker.images.required_env', [])[$image->name()] ?? []);
        $env = [];

        foreach ($lines as $line) {
            if (! is_string($line) || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1 || Str::is($hidden, $key)) {
                continue;
            }

            $env[$key] = ['key' => $key, 'default' => $value, 'required' => false];
        }

        foreach ($mandatory as $key) {
            $env[$key] = ['key' => $key, 'default' => $env[$key]['default'] ?? '', 'required' => true];
        }

        return array_values($env);
    }

    /**
     * A named volume per declared path: `memos-data` for one, `heimdall-config`
     * when there are several. Names follow the rule the volume form enforces.
     *
     * @param  list<string>  $volumes
     * @return list<array{path: string, name: string}>
     */
    private function suggestedVolumes(ImageReference $image, array $volumes): array
    {
        // `gotify/server`, `vaultwarden/server`: the project is the namespace.
        $short = $image->shortName();
        $parts = explode('/', $image->repository);

        if (in_array($short, ['server', 'app', 'web', 'core', 'api', 'docker', 'image', 'community', 'ce', 'oss'], true) && count($parts) > 1) {
            $short = $parts[count($parts) - 2];
        }

        $base = Str::slug($short) ?: 'app';
        $suggested = [];
        $used = [];

        foreach ($volumes as $path) {
            $segment = Str::slug((string) collect(explode('/', trim($path, '/')))->filter()->last());
            $name = count($volumes) === 1 || $segment === '' ? $base.'-data' : $base.'-'.$segment;

            for ($n = 2; in_array($name, $used, true); $n++) {
                $name = $base.'-'.($segment ?: 'data').'-'.$n;
            }

            $used[] = $name;
            $suggested[] = ['path' => $path, 'name' => $name];
        }

        return $suggested;
    }

    /**
     * Does the image keep its program in `/app`?
     *
     * Read from the build history, the same way `appscan.py` measured that 28%
     * of popular images do. Only what lands IN `/app` counts: a `COPY --from`
     * whose SOURCE is a builder's `/app` (Vaultwarden, IT-Tools) leaves nothing
     * there, and an empty `mkdir /app` (n8n) holds nothing to hide.
     *
     * @param  array<string, mixed>  $config
     */
    private function usesAppDir(array $config, string $workdir): bool
    {
        $inApp = fn (string $path): bool => (bool) preg_match('#^/app(/|$)#', trim($path, '"\''));

        if ($inApp($workdir)) {
            return true;
        }

        $runtime = (array) ($config['config'] ?? []);
        $command = implode(' ', array_filter(array_merge((array) ($runtime['Entrypoint'] ?? []), (array) ($runtime['Cmd'] ?? [])), 'is_string'));

        if (preg_match('#(^|[\s=])(\./)?/app(/|\s|$)#', $command) === 1) {
            return true;
        }

        foreach ((array) ($config['history'] ?? []) as $entry) {
            $line = trim((string) ($entry['created_by'] ?? ''));
            $line = (string) preg_replace('/\s*#\s*buildkit\s*$/', '', $line);
            $line = (string) preg_replace('#^/bin/sh -c (\#\(nop\)\s*)?#', '', $line);

            if (preg_match('/^(COPY|ADD)\b(.*)$/', $line, $match) === 1) {
                // Destination is the last argument; everything before it is a source.
                $args = preg_split('/\s+/', trim((string) preg_replace('/--\S+/', '', $match[2]))) ?: [];
                $destination = (string) end($args);

                if ($inApp($destination)) {
                    return true;
                }

                continue;
            }

            if (preg_match('/^WORKDIR\s+(\S+)/', $line, $match) === 1) {
                continue; // The final WORKDIR is in the config; earlier ones are not where it runs.
            }

            if (preg_match('#\b(cp|mv|ln|git clone|tar|unzip)\b[^;&|]*\s/app(/|\s|$|")#', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(ImageReference $image, string $reason): array
    {
        $message = match ($reason) {
            ImageLookupException::TAG_NOT_FOUND => __('docker.image.tag_not_found', ['image' => $image->name(), 'tag' => $image->manifestReference()]),
            ImageLookupException::CREDENTIAL_REJECTED => __('docker.image.credential_rejected', ['image' => $image->name()]),
            default => __('docker.image.not_found', ['image' => $image->name()]),
        };

        return [
            'image' => $image->full(),
            'found' => false,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    /**
     * The architecture Docker on this box pulls — the panel runs on the server
     * it manages, so its own machine type is the answer.
     */
    private function serverArchitecture(): string
    {
        $configured = config('server.docker.images.architecture');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return match (strtolower(php_uname('m'))) {
            'aarch64', 'arm64' => 'arm64',
            'armv7l', 'armv6l' => 'arm',
            'i386', 'i686' => '386',
            'ppc64le' => 'ppc64le',
            's390x' => 's390x',
            default => 'amd64',
        };
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1).' GB'
            : round($bytes / 1024 ** 2).' MB';
    }
}
