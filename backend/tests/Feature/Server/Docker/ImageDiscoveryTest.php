<?php

use App\Models\Permission;
use App\Models\Registry;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\User;
use App\Services\Server\Docker\Images\ImageReference;
use App\Services\Server\Docker\Images\ImageTags;
use App\Support\RemoteHost;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Image discovery (DS-02): search, tags and inspect, read from registry APIs
 * without pulling. Every registry here is faked; the shapes are the real ones,
 * captured from Docker Hub, GHCR and Quay on 2026-10-07.
 *
 * Helpers are prefixed `img` — Pest loads every test file into one process
 * and a bare helper name collides with another suite's.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    config(['server.docker.images.architecture' => 'amd64']);

    // Every name resolves to a public address unless a test says otherwise:
    // since DS-08 a name the panel cannot resolve is not connected to at all.
    RemoteHost::resolveUsing(fn (string $host): array => ['203.0.113.10']);
});

function imgAs(?User $user = null): array
{
    return ['Authorization' => 'Bearer '.($user ?? test()->admin)->createToken('t')->plainTextToken];
}

function imgDigest(string $seed): string
{
    return 'sha256:'.hash('sha256', $seed);
}

/** An image config blob, as a registry serves it. */
function imgConfig(array $config = [], array $history = [], string $architecture = 'amd64'): array
{
    return [
        'architecture' => $architecture,
        'os' => 'linux',
        'config' => $config,
        'history' => array_map(fn (string $line): array => ['created_by' => $line], $history),
    ];
}

/**
 * A fake registry. `$images` maps "host/repo:tag" to a config blob (single
 * manifest) or to ['index' => [arch => config]] for a manifest list.
 *
 * @param  array<string, mixed>  $images
 * @param  array<string, mixed>  $options  oci, challenge, token_status
 */
function imgRegistry(array $images, array $options = []): void
{
    $oci = $options['oci'] ?? false;
    $listType = $oci ? 'application/vnd.oci.image.index.v1+json' : 'application/vnd.docker.distribution.manifest.list.v2+json';
    $manifestType = $oci ? 'application/vnd.oci.image.manifest.v1+json' : 'application/vnd.docker.distribution.manifest.v2+json';

    $manifests = [];
    $blobs = [];

    foreach ($images as $reference => $definition) {
        [$host, $rest] = explode('/', $reference, 2);
        [$repo, $tag] = explode(':', $rest, 2);

        $single = function (array $config, string $arch) use (&$blobs, $manifestType, $host, $repo): array {
            $configDigest = imgDigest(json_encode($config));
            $blobs[$host.'/'.$repo.'@'.$configDigest] = $config;

            return [
                'schemaVersion' => 2,
                'mediaType' => $manifestType,
                'config' => ['mediaType' => 'application/vnd.docker.container.image.v1+json', 'digest' => $configDigest, 'size' => 1000],
                'layers' => [['digest' => imgDigest($arch.'l1'), 'size' => 30_000_000], ['digest' => imgDigest($arch.'l2'), 'size' => 2_000_000]],
            ];
        };

        if (isset($definition['index'])) {
            $entries = [];

            foreach ($definition['index'] as $arch => $config) {
                $manifest = $single($config, $arch);
                $digest = imgDigest(json_encode($manifest));
                $manifests[$host.'/'.$repo.':'.$digest] = $manifest;
                [$a, $variant] = array_pad(explode('/', $arch, 2), 2, null);
                $entries[] = ['mediaType' => $manifestType, 'digest' => $digest, 'size' => 500,
                    'platform' => array_filter(['architecture' => $a, 'os' => 'linux', 'variant' => $variant])];
            }

            // Buildkit's attestation entry, which is not an image.
            $entries[] = ['mediaType' => $manifestType, 'digest' => imgDigest('att'), 'size' => 100,
                'platform' => ['architecture' => 'unknown', 'os' => 'unknown']];

            $manifests[$host.'/'.$repo.':'.$tag] = ['schemaVersion' => 2, 'mediaType' => $listType, 'manifests' => $entries];
        } else {
            $manifests[$host.'/'.$repo.':'.$tag] = $single($definition, 'amd64');
        }
    }

    Http::fake(function (Request $request) use ($manifests, $blobs, $options) {
        $url = parse_url($request->url());
        $host = $url['host'];
        $path = $url['path'] ?? '';

        // Token endpoints.
        if (in_array($host, ['auth.docker.io', 'ghcr.io', 'quay.io', 'registry.example.com'], true)
            && (str_ends_with($path, '/token') || str_ends_with($path, '/v2/auth'))) {
            return Http::response(['token' => 'tok-'.$host], $options['token_status'] ?? 200);
        }

        if (preg_match('#^/v2/(.+)/(manifests|blobs)/(.+)$#', $path, $m) !== 1) {
            return Http::response('', 404);
        }

        $challenge = $options['challenge'] ?? 'Bearer realm="https://'.($host === 'registry-1.docker.io' ? 'auth.docker.io' : $host).'/token",service="'.$host.'"';

        if (! $request->hasHeader('Authorization')) {
            return Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401, ['WWW-Authenticate' => $challenge]);
        }

        $registry = $host === 'registry-1.docker.io' ? 'docker.io' : $host;
        [, $repo, $kind, $ref] = $m;

        if ($kind === 'blobs') {
            return isset($blobs[$registry.'/'.$repo.'@'.$ref])
                ? Http::response($blobs[$registry.'/'.$repo.'@'.$ref])
                : Http::response(['errors' => [['code' => 'BLOB_UNKNOWN']]], 404);
        }

        $manifest = $manifests[$registry.'/'.$repo.':'.$ref] ?? null;

        if ($manifest === null) {
            $known = collect(array_keys($manifests))->contains(fn (string $k): bool => str_starts_with($k, $registry.'/'.$repo.':'));

            return $known
                ? Http::response(['errors' => [['code' => 'MANIFEST_UNKNOWN', 'message' => 'manifest unknown']]], 404)
                : Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401, ['WWW-Authenticate' => $challenge]);
        }

        $body = json_encode($manifest);

        return Http::response($body, 200, [
            'Content-Type' => $manifest['mediaType'],
            'Docker-Content-Digest' => 'sha256:'.hash('sha256', $body),
        ]);
    });
}

function imgMemos(): array
{
    // neosmemo/memos:0.31.0 amd64, abridged from the real config.
    return imgConfig(
        [
            'ExposedPorts' => ['5230/tcp' => (object) []],
            'Volumes' => ['/var/opt/memos' => (object) []],
            'Env' => ['PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'TZ=UTC', 'MEMOS_PORT=5230'],
            'WorkingDir' => '/usr/local/memos',
            'Entrypoint' => ['/usr/local/memos/entrypoint.sh', '/usr/local/memos/memos'],
        ],
        ['WORKDIR /usr/local/memos', 'COPY /backend-build/memos /usr/local/memos/ # buildkit', 'EXPOSE map[5230/tcp:{}]'],
    );
}

/*
 * The contract. DS-04's frontend is built against exactly these keys.
 */

it('inspects a Hub image through its manifest list and returns the DS-02 contract', function () {
    imgRegistry(['docker.io/neosmemo/memos:0.31.0' => ['index' => [
        'arm64' => imgConfig(['ExposedPorts' => ['9999/tcp' => []]], [], 'arm64'),
        'amd64' => imgMemos(),
        'arm/v7' => imgConfig([], [], 'arm'),
    ]]]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=neosmemo/memos:0.31.0')->assertOk();

    expect($response->json())->toHaveKeys(['image', 'digest', 'found', 'exposed_ports', 'suggested_port', 'port_confidence',
        'volumes', 'suggested_volumes', 'env', 'workdir', 'user', 'size_bytes', 'architectures', 'uses_app_dir', 'warnings'])
        ->and($response->json('image'))->toBe('neosmemo/memos:0.31.0')
        ->and($response->json('found'))->toBeTrue()
        ->and($response->json('digest'))->toStartWith('sha256:')
        // amd64's port, not arm64's: the server's own architecture was chosen.
        ->and($response->json('exposed_ports'))->toBe([5230])
        ->and($response->json('suggested_port'))->toBe(5230)
        ->and($response->json('port_confidence'))->toBe('declared')
        ->and($response->json('volumes'))->toBe(['/var/opt/memos'])
        ->and($response->json('suggested_volumes'))->toBe([['path' => '/var/opt/memos', 'name' => 'memos-data']])
        // PATH is plumbing, not a setting.
        ->and($response->json('env'))->toBe([
            ['key' => 'TZ', 'default' => 'UTC', 'required' => false],
            ['key' => 'MEMOS_PORT', 'default' => '5230', 'required' => false],
        ])
        ->and($response->json('workdir'))->toBe('/usr/local/memos')
        ->and($response->json('size_bytes'))->toBe(32_001_000)
        // The attestation entry is not an architecture.
        ->and($response->json('architectures'))->toBe(['arm64', 'amd64', 'arm/v7'])
        ->and($response->json('uses_app_dir'))->toBeFalse()
        ->and($response->json('warnings'))->toBe([]);

    // Anonymous pull token, scoped to this repository, from Hub's own realm.
    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://auth.docker.io/token')
        && str_contains(urldecode($r->url()), 'scope=repository:neosmemo/memos:pull')
        && ! $r->hasHeader('Authorization'));
});

it('reads a single OCI manifest from GHCR without a manifest list', function () {
    imgRegistry(['ghcr.io/usememos/memos:0.31.0' => imgMemos()], ['oci' => true]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=ghcr.io/usememos/memos:0.31.0')->assertOk();

    expect($response->json('found'))->toBeTrue()
        ->and($response->json('image'))->toBe('ghcr.io/usememos/memos:0.31.0')
        ->and($response->json('suggested_port'))->toBe(5230)
        // No list to read platforms from, so the config's own architecture.
        ->and($response->json('architectures'))->toBe(['amd64']);

    // Asked with every manifest type a registry might answer with.
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/manifests/')
        && str_contains($r->header('Accept')[0] ?? '', 'application/vnd.oci.image.index.v1+json')
        && str_contains($r->header('Accept')[0] ?? '', 'application/vnd.docker.distribution.manifest.v2+json'));
});

it('reads Quay and resolves lscr.io through GHCR', function () {
    imgRegistry([
        'quay.io/hedgedoc/hedgedoc:1.10.3' => imgConfig(['ExposedPorts' => ['3000/tcp' => []]]),
        'ghcr.io/linuxserver/heimdall:2.8.3' => imgConfig(['ExposedPorts' => ['80/tcp' => [], '443/tcp' => []], 'Volumes' => ['/config' => []]]),
    ]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=quay.io/hedgedoc/hedgedoc:1.10.3')
        ->assertOk()->assertJsonPath('suggested_port', 3000);

    $heimdall = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=lscr.io/linuxserver/heimdall:2.8.3')->assertOk();

    // The reference stays what the user typed; only the API host differs.
    expect($heimdall->json('image'))->toBe('lscr.io/linuxserver/heimdall:2.8.3')
        // 80 and 443 declared: the panel terminates TLS, so it proxies to 80.
        ->and($heimdall->json('exposed_ports'))->toBe([80, 443])
        ->and($heimdall->json('suggested_port'))->toBe(80)
        ->and($heimdall->json('suggested_volumes'))->toBe([['path' => '/config', 'name' => 'heimdall-data']])
        ->and(implode(' ', $heimdall->json('warnings')))->toContain('80, 443');

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'lscr.io'));
});

/*
 * Ports.
 */

it('prefers a web port among several and falls back to the lowest plain one', function () {
    imgRegistry([
        'docker.io/acme/multi:1' => imgConfig(['ExposedPorts' => ['9090/tcp' => [], '8080/tcp' => [], '53/udp' => []]]),
        'docker.io/acme/odd:1' => imgConfig(['ExposedPorts' => ['8443/tcp' => [], '7001/tcp' => [], '7002/tcp' => []]]),
    ]);

    $multi = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/multi:1')->assertOk();
    $odd = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/odd:1')->assertOk();

    // UDP is not something nginx can proxy to.
    expect($multi->json('exposed_ports'))->toBe([8080, 9090])
        ->and($multi->json('suggested_port'))->toBe(8080)
        ->and($odd->json('suggested_port'))->toBe(7001);
});

it('never suggests a TLS port, even when it is the only one declared (DS-09)', function () {
    // Proxied over plain HTTP, 443 answers 400 — which the readiness check
    // counts as answering — and every visitor gets that 400.
    imgRegistry([
        'docker.io/acme/tlsonly:1' => imgConfig(['ExposedPorts' => ['443/tcp' => []]]),
        'docker.io/acme/tlsboth:1' => imgConfig(['ExposedPorts' => ['443/tcp' => [], '8443/tcp' => []]]),
    ]);

    foreach (['acme/tlsonly:1', 'acme/tlsboth:1'] as $image) {
        $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image='.$image)->assertOk();

        expect($response->json('suggested_port'))->toBeNull()
            ->and($response->json('port_confidence'))->toBe('none');
    }
});

it('guesses from the known-ports table, and says so, when an image declares nothing', function () {
    config(['server.docker.images.known_ports' => ['acme/quiet' => 4321]]);
    imgRegistry([
        'docker.io/acme/quiet:1' => imgConfig([]),
        'docker.io/acme/silent:1' => imgConfig([]),
    ]);

    $quiet = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/quiet:1')->assertOk();
    $silent = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/silent:1')->assertOk();

    expect($quiet->json('suggested_port'))->toBe(4321)
        ->and($quiet->json('port_confidence'))->toBe('guessed')
        // Nothing declared, nothing known: the UI must ask, and is told to.
        ->and($silent->json('suggested_port'))->toBeNull()
        ->and($silent->json('port_confidence'))->toBe('none')
        ->and($silent->json('warnings'))->toContain(__('docker.image.warning_no_port'));
});

it('adds the known-volumes table to what an image declares, so its data gets a volume', function () {
    config(['server.docker.images.known_volumes' => ['acme/kuma' => ['/app/data'], 'acme/both' => ['/data']]]);
    imgRegistry([
        // Uptime Kuma 2.x: its data lives in /app/data and no VOLUME says so.
        'docker.io/acme/kuma:2' => imgConfig(['ExposedPorts' => ['3001/tcp' => []]]),
        'docker.io/acme/both:1' => imgConfig(['Volumes' => ['/data' => [], '/config' => []]]),
        'docker.io/acme/other:1' => imgConfig([]),
    ]);

    $kuma = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/kuma:2')->assertOk();
    $both = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/both:1')->assertOk();
    $other = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/other:1')->assertOk();

    expect($kuma->json('volumes'))->toBe(['/app/data'])
        ->and(array_column($kuma->json('suggested_volumes'), 'path'))->toBe(['/app/data'])
        // Declared and known at once: listed once.
        ->and($both->json('volumes'))->toBe(['/config', '/data'])
        ->and($other->json('volumes'))->toBe([]);
});

/*
 * Settings. An image cannot declare a required setting, so none is guessed.
 */

it('never marks an empty ENV as required, because working images ship them', function () {
    // changedetection.io's real `LOGGER_LEVEL=`: the image starts without it,
    // and the create form blocks Deploy on an empty required value.
    imgRegistry(['ghcr.io/dgtlmoon/changedetection.io:0.60.8' => imgConfig([
        'ExposedPorts' => ['5000/tcp' => []],
        'Env' => ['PATH=/usr/bin', 'PYTHON_VERSION=3.12.1', 'PYTHONPATH=/app', 'LOGGER_LEVEL=', 'NGINX_VERSION=1.27'],
    ])]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=ghcr.io/dgtlmoon/changedetection.io:0.60.8')->assertOk();

    expect($response->json('env'))->toBe([['key' => 'LOGGER_LEVEL', 'default' => '', 'required' => false]])
        ->and($response->json('warnings'))->toBe([__('docker.image.warning_empty_env', ['keys' => 'LOGGER_LEVEL'])]);
});

it('marks settings required only from the documented table', function () {
    imgRegistry(['docker.io/library/postgres:18' => imgConfig([
        'ExposedPorts' => ['5432/tcp' => []],
        'Env' => ['PG_MAJOR=18', 'PGDATA=/var/lib/postgresql/18/docker'],
    ])]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=postgres:18')->assertOk();

    // Not in the image's ENV at all, and still the one it will not start without.
    expect($response->json('env'))->toBe([['key' => 'POSTGRES_PASSWORD', 'default' => '', 'required' => true]])
        ->and($response->json('warnings'))->toContain(__('docker.image.warning_required_env', ['keys' => 'POSTGRES_PASSWORD']));
});

/*
 * /app — what DS-01 measured, read the same way appscan.py did.
 */

it('detects an image that keeps its program in /app', function (array $config, array $history, bool $expected) {
    imgRegistry(['docker.io/acme/probe:1' => imgConfig($config, $history)]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/probe:1')
        ->assertOk()->assertJsonPath('uses_app_dir', $expected);
})->with([
    'WORKDIR /app (Gotify)' => [['WorkingDir' => '/app'], [], true],
    'files copied into /app (changedetection)' => [['WorkingDir' => '/'], ['COPY changedetectionio /app/changedetectionio # buildkit'], true],
    'entrypoint under /app' => [['Cmd' => ['python', '/app/run.py']], [], true],
    'cp into /app in a RUN' => [[], ['/bin/sh -c cp -r /src /app'], true],
    // Vaultwarden: /app is the SOURCE of a COPY --from, nothing lands there.
    'COPY from a builder\'s /app' => [['WorkingDir' => '/'], ['COPY /app/target/release/vaultwarden . # buildkit'], false],
    // n8n: an empty mkdir holds nothing to hide.
    'empty mkdir /app' => [['WorkingDir' => '/home/node'], ['/bin/sh -c mkdir -p /app'], false],
    'nothing to do with /app' => [['WorkingDir' => '/usr/share/nginx/html'], ['COPY index.html /usr/share/nginx/html/ # buildkit'], false],
]);

/*
 * Answers about the image: 200, found:false, a sentence.
 */

it('reports a missing tag as not found, naming the version', function () {
    imgRegistry(['docker.io/library/nginx:1.27' => imgConfig(['ExposedPorts' => ['80/tcp' => []]])]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=nginx:nosuchtag')
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonPath('reason', 'tag_not_found')
        ->assertJsonPath('message', __('docker.image.tag_not_found', ['image' => 'nginx', 'tag' => 'nosuchtag']));
});

it('reports a repository the registry refuses as not found, or private', function () {
    // Hub answers a missing repository with 401 even after a token, exactly as
    // it answers a private one.
    imgRegistry([]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=nosuchuser/nosuchrepo')
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonPath('reason', 'not_found')
        ->assertJsonPath('image', 'nosuchuser/nosuchrepo:latest');
});

it('treats a refused token request as not found', function () {
    // GHCR answers 403 from its token endpoint for a repository that does not exist.
    imgRegistry([], ['token_status' => 403]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=ghcr.io/nobody/nothing:1')
        ->assertOk()->assertJsonPath('found', false)->assertJsonPath('reason', 'not_found');
});

it('warns when the image has no build for this server', function () {
    imgRegistry(['docker.io/acme/armonly:1' => ['index' => ['arm64' => imgConfig(['ExposedPorts' => ['80/tcp' => []]], [], 'arm64')]]]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/armonly:1')->assertOk();

    expect($response->json('found'))->toBeTrue()
        ->and($response->json('architectures'))->toBe(['arm64'])
        ->and($response->json('warnings'))->toContain(__('docker.image.warning_no_build', ['architecture' => 'linux/amd64']));
});

it('warns about a large download', function () {
    config(['server.docker.images.large_bytes' => 10_000_000]);
    imgRegistry(['docker.io/acme/big:1' => imgConfig(['ExposedPorts' => ['80/tcp' => []]])]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=acme/big:1')
        ->assertOk()->assertJsonPath('warnings', [__('docker.image.warning_large', ['size' => '31 MB'])]);
});

/*
 * Private registries.
 */

function imgCredential(array $attributes = []): Registry
{
    return Registry::forceCreate(array_merge([
        'name' => 'Private', 'registry' => 'registry.example.com', 'username' => 'deploy',
        'config' => ['token' => 'reg-SECRET-token'],
    ], $attributes));
}

it('sends a stored credential to the token endpoint of its own registry', function () {
    $credential = imgCredential();
    imgRegistry(['registry.example.com/team/app:2' => imgConfig(['ExposedPorts' => ['3000/tcp' => []]])]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=registry.example.com/team/app:2&registry_id='.$credential->id)
        ->assertOk()->assertJsonPath('suggested_port', 3000);

    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://registry.example.com/token')
        && ($r->header('Authorization')[0] ?? '') === 'Basic '.base64_encode('deploy:reg-SECRET-token'));
});

it('answers a Basic challenge with the credential directly', function () {
    $credential = imgCredential();
    imgRegistry(['registry.example.com/team/app:2' => imgConfig(['ExposedPorts' => ['3000/tcp' => []]])], [
        'challenge' => 'Basic realm="Registry"',
    ]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=registry.example.com/team/app:2&registry_id='.$credential->id)
        ->assertOk()->assertJsonPath('found', true);

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/manifests/2')
        && ($r->header('Authorization')[0] ?? '') === 'Basic '.base64_encode('deploy:reg-SECRET-token'));
});

it('never sends a credential to a registry it was not saved for', function () {
    $credential = imgCredential(['registry' => 'ghcr.io']);
    imgRegistry(['quay.io/acme/app:1' => imgConfig(['ExposedPorts' => ['80/tcp' => []]])]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=quay.io/acme/app:1&registry_id='.$credential->id)
        ->assertOk()->assertJsonPath('found', true);

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->header('Authorization')[0] ?? '', 'Basic'));
});

it('says the credential was refused, rather than that the image does not exist', function () {
    $credential = imgCredential();
    imgRegistry([], ['token_status' => 401]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=registry.example.com/team/app:2&registry_id='.$credential->id)
        ->assertOk()->assertJsonPath('found', false)->assertJsonPath('reason', 'credential_rejected');
});

it('never puts the stored token in a response', function () {
    $credential = imgCredential();
    imgRegistry([], ['token_status' => 401]);

    $body = $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=registry.example.com/team/app:2&registry_id='.$credential->id)->getContent();

    expect($body)->not->toContain('reg-SECRET-token');
});

/*
 * "We could not ask" is not a verdict on the image.
 */

it('answers 503 when the registry cannot be reached', function () {
    Http::fake(fn () => Http::failedConnection());

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=nginx:1.27')
        ->assertStatus(503)
        ->assertJsonPath('reason', 'unreachable')
        ->assertJsonPath('message', __('docker.image.registry_unreachable', ['registry' => 'docker.io']));
});

it('answers 503 with its own sentence when Docker Hub rate-limits the server', function () {
    Http::fake([
        'auth.docker.io/*' => Http::response(['token' => 't']),
        '*' => Http::response(['errors' => [['code' => 'TOOMANYREQUESTS']]], 429),
    ]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=nginx:1.27')
        ->assertStatus(503)->assertJsonPath('message', __('docker.image.rate_limited'));
});

it('answers 503 on a registry error rather than claiming the image is missing', function () {
    Http::fake(['*' => Http::response('', 502)]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=nginx:1.27')->assertStatus(503);
});

it('refuses to connect to a loopback registry, without sending anything', function () {
    Http::fake();

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=127.0.0.1:5000/app:1')
        ->assertStatus(422)->assertJsonValidationErrors('image');
    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=127.0.0.1:5000/app')
        ->assertStatus(422)->assertJsonValidationErrors('image');

    Http::assertNothingSent();
});

it('refuses a token realm that points at loopback', function () {
    imgRegistry(['quay.io/acme/app:1' => imgConfig([])], [
        'challenge' => 'Bearer realm="https://127.0.0.1/token",service="quay.io"',
    ]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=quay.io/acme/app:1')->assertStatus(422);

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '127.0.0.1'));
});

it('caches an answer, so a second look sends nothing', function () {
    imgRegistry(['docker.io/neosmemo/memos:0.31.0' => imgMemos()]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=neosmemo/memos:0.31.0')->assertOk();
    $sent = count(Http::recorded());

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=neosmemo/memos:0.31.0')->assertOk()->assertJsonPath('suggested_port', 5230);

    expect(count(Http::recorded()))->toBe($sent);
});

/*
 * Reference parsing — Docker's rules, so the panel and `docker pull` agree.
 */

it('parses image references the way Docker does', function (string $input, ?array $expected) {
    $reference = ImageReference::parse($input);

    if ($expected === null) {
        expect($reference)->toBeNull();

        return;
    }

    expect([$reference->registry, $reference->repository, $reference->tag, $reference->full(), $reference->apiHost()])->toBe($expected);
})->with([
    ['nginx', ['docker.io', 'library/nginx', 'latest', 'nginx:latest', 'registry-1.docker.io']],
    ['docker.io/library/nginx:1.27', ['docker.io', 'library/nginx', '1.27', 'nginx:1.27', 'registry-1.docker.io']],
    ['usememos/memos:0.31.0', ['docker.io', 'usememos/memos', '0.31.0', 'usememos/memos:0.31.0', 'registry-1.docker.io']],
    ['ghcr.io/usememos/memos', ['ghcr.io', 'usememos/memos', 'latest', 'ghcr.io/usememos/memos:latest', 'ghcr.io']],
    // The `:5000` is a port, not a tag.
    ['registry.example.com:5000/team/app', ['registry.example.com:5000', 'team/app', 'latest', 'registry.example.com:5000/team/app:latest', 'registry.example.com:5000']],
    ['lscr.io/linuxserver/heimdall:2.8.3', ['lscr.io', 'linuxserver/heimdall', '2.8.3', 'lscr.io/linuxserver/heimdall:2.8.3', 'ghcr.io']],
    // `myhost` has no dot: Docker reads it as a Hub namespace, and so do we.
    ['myhost/app', ['docker.io', 'myhost/app', 'latest', 'myhost/app:latest', 'registry-1.docker.io']],
    ['Nginx', null],
    ['nginx:', null],
    ['nginx@sha256:short', null],
    ['-nginx', null],
    ['ghcr.io/a//b', null],
]);

it('refuses a reference Docker would refuse', function () {
    Http::fake();

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=Usememos/Memos')
        ->assertStatus(422)->assertJsonValidationErrors('image');

    Http::assertNothingSent();
});

/*
 * Tags.
 */

it('ranks Hub tags: exact versions first, newest first, then variants, then moving tags', function () {
    Http::fake(['hub.docker.com/v2/repositories/neosmemo/memos/tags*' => Http::response(['results' => [
        ['name' => 'latest', 'last_updated' => '2026-10-01T00:00:00Z', 'tag_status' => 'active'],
        ['name' => 'canary', 'last_updated' => '2026-10-06T00:00:00Z', 'tag_status' => 'active'],
        ['name' => '0.31', 'last_updated' => '2026-10-01T00:00:00Z', 'tag_status' => 'active'],
        ['name' => '0.31.0', 'last_updated' => '2026-10-01T00:00:00Z', 'tag_status' => 'active'],
        ['name' => '0.31.0-alpine', 'last_updated' => '2026-10-01T00:00:00Z', 'tag_status' => 'active'],
        ['name' => '0.32.0-rc1', 'last_updated' => '2026-10-05T00:00:00Z', 'tag_status' => 'active'],
        ['name' => '0.30.0', 'last_updated' => '2026-08-01T00:00:00Z', 'tag_status' => 'active'],
        ['name' => 'sha-abc123', 'last_updated' => '2026-10-06T00:00:00Z', 'tag_status' => 'active'],
        ['name' => '0.9.0', 'last_updated' => '2024-01-01T00:00:00Z', 'tag_status' => 'inactive'],
    ]])]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=neosmemo/memos')->assertOk();

    expect($response->json('image'))->toBe('neosmemo/memos')
        // The release candidate is newer and still not recommended.
        ->and($response->json('recommended'))->toBe('0.31.0')
        ->and(array_column($response->json('tags'), 'name'))->toBe(['0.31.0', '0.31', '0.30.0', '0.31.0-alpine', 'latest', 'canary', '0.32.0-rc1', 'sha-abc123'])
        ->and($response->json('tags.0'))->toBe(['name' => '0.31.0', 'updated_at' => '2026-10-01T00:00:00Z', 'stable' => true])
        ->and($response->json('tags.4.stable'))->toBeFalse();
});

it('recommends a dotted release over a bare number that only outranks it numerically', function () {
    // The real codercom/code-server tag list, newest first (2026-10-07).
    Http::fake(['hub.docker.com/v2/repositories/codercom/code-server/tags*' => Http::response(['results' => array_map(
        fn (string $name): array => ['name' => $name, 'last_updated' => '2026-10-02T00:00:00Z', 'tag_status' => 'active'],
        ['4.140.0-39', '39', '4.140.0-fedora', 'fedora', '4.140.0-trixie', 'trixie', '4.140.0', 'latest'],
    )])]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=codercom/code-server')
        ->assertOk()->assertJsonPath('recommended', '4.140.0');

    // An image that only publishes bare numbers still gets its newest one.
    Http::fake(['hub.docker.com/v2/repositories/acme/counted/tags*' => Http::response(['results' => [
        ['name' => '7', 'tag_status' => 'active'], ['name' => '12', 'tag_status' => 'active'], ['name' => 'latest', 'tag_status' => 'active'],
    ]])]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=acme/counted')
        ->assertOk()->assertJsonPath('recommended', '12');
});

/**
 * GHCR repositories answering `tags/list` with these names, in the order the
 * registry sorts them, and Hub repositories answering with these tag rows.
 * One fake for both: Laravel keeps the first fake that matches, so a second
 * call in the same test would never be reached.
 *
 * @param  array<string, list<string>>  $ghcr
 * @param  array<string, list<array<string, string>>>  $hub
 */
function imgTagSources(array $ghcr, array $hub = []): void
{
    Http::fake(function (Request $r) use ($ghcr, $hub) {
        foreach ($hub as $repository => $rows) {
            if (str_contains($r->url(), 'hub.docker.com/v2/repositories/'.$repository.'/tags')) {
                return Http::response(['results' => $rows]);
            }
        }

        if (str_contains($r->url(), '/token')) {
            return Http::response(['token' => 't']);
        }

        if (! $r->hasHeader('Authorization')) {
            return Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io"']);
        }

        foreach ($ghcr as $repository => $tags) {
            if (str_contains($r->url(), '/v2/'.$repository.'/tags/list')) {
                return Http::response(['name' => $repository, 'tags' => $tags]);
            }
        }

        return Http::response('', 404);
    });
}

it('does not recommend a leftover date tag over the current version (DS-09)', function () {
    // tags/list carries no dates: a date-shaped tag beside real versions is
    // a leftover, and numerically it beat them.
    imgTagSources([
        'acme/heimdall' => ['2.8.2', '2.8.3', '2021.11.28', 'latest'],
        'home-assistant/home-assistant' => ['2026.10.1', '2026.9.4', 'beta', 'stable'],
    ], [
        'acme/calver' => [
            ['name' => '2026.10.1', 'last_updated' => '2026-10-01T00:00:00Z', 'tag_status' => 'active'],
            ['name' => '1.9.0', 'last_updated' => '2023-01-01T00:00:00Z', 'tag_status' => 'active'],
        ],
    ]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=ghcr.io/acme/heimdall')->assertOk();

    expect($response->json('recommended'))->toBe('2.8.3')
        ->and(array_column($response->json('tags'), 'name'))->toBe(['2.8.3', '2.8.2', '2021.11.28', 'latest']);

    // On Hub the dates say which scheme is current. Here the date tags are
    // the newer ones — a project that moved to CalVer — so they win.
    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=acme/calver')
        ->assertOk()->assertJsonPath('recommended', '2026.10.1');

    // And an image that only publishes dates is left alone.
    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=ghcr.io/home-assistant/home-assistant')
        ->assertOk()->assertJsonPath('recommended', '2026.10.1');
});

it('prefers a tag that says it is stable over the alphabetically first one, and otherwise asks (DS-09)', function () {
    imgTagSources(['acme/branches' => ['alpha', 'main', 'stable'], 'acme/guess' => ['beta', 'prod']]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=ghcr.io/acme/branches')
        ->assertOk()->assertJsonPath('recommended', 'stable');

    // Nothing to go on, and tags/list is sorted by name: no guess.
    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=ghcr.io/acme/guess')
        ->assertOk()->assertJsonPath('recommended', null);
});

it('always lists the version it recommends (DS-09)', function () {
    // Thirty bare-number aliases rank above the one dotted release, and the
    // recommendation skips them — it was at ranked index 31 of a list of 20.
    Http::fake(['hub.docker.com/v2/repositories/acme/aliases/tags*' => Http::response(['results' => array_map(
        fn (string $name): array => ['name' => $name, 'last_updated' => '2026-10-02T00:00:00Z', 'tag_status' => 'active'],
        [...array_map('strval', range(60, 30)), '4.104.1', 'latest'],
    )])]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=acme/aliases&limit=20')->assertOk();

    expect($response->json('recommended'))->toBe('4.104.1')
        ->and($response->json('tags'))->toHaveCount(20)
        ->and(array_column($response->json('tags'), 'name'))->toContain('4.104.1');
});

it('reads only whole words as pre-release markers (DS-09)', function () {
    Http::fake(['hub.docker.com/v2/repositories/acme/variants/tags*' => Http::response(['results' => array_map(
        fn (string $name): array => ['name' => $name, 'last_updated' => '2026-10-02T00:00:00Z', 'tag_status' => 'active'],
        ['3.2.1-nextcloud', '3.2.1-devuan', '3.2.1-rc1', '3.2.1-beta', '3.2.1-devel', '3.2.1'],
    )])]);

    $tags = collect($this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=acme/variants')->assertOk()->json('tags'))
        ->pluck('stable', 'name');

    expect($tags['3.2.1-nextcloud'])->toBeTrue()
        ->and($tags['3.2.1-devuan'])->toBeTrue()
        ->and($tags['3.2.1-rc1'])->toBeFalse()
        ->and($tags['3.2.1-beta'])->toBeFalse()
        ->and($tags['3.2.1-devel'])->toBeFalse();
});

it('still reads -prerelease and -testing as pre-releases (DS-12)', function () {
    // Anchored as whole words, `pre` and `test` stopped at the next letter.
    Http::fake(['hub.docker.com/v2/repositories/acme/prerelease/tags*' => Http::response(['results' => array_map(
        fn (string $name): array => ['name' => $name, 'last_updated' => '2026-10-02T00:00:00Z', 'tag_status' => 'active'],
        ['1.2.3-prerelease', '1.2.3-testing', '1.2.3-testing2', '1.2.3-trixie'],
    )])]);

    $tags = collect($this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=acme/prerelease')->assertOk()->json('tags'))
        ->pluck('stable', 'name');

    expect($tags['1.2.3-prerelease'])->toBeFalse()
        ->and($tags['1.2.3-testing'])->toBeFalse()
        ->and($tags['1.2.3-testing2'])->toBeFalse()
        ->and($tags['1.2.3-trixie'])->toBeTrue();
});

it('keeps no state between images on one ImageTags (DS-12)', function () {
    // The demoted date tags lived on the instance, shared by rank() and
    // recommended(). Correct while every call ran both in order; wrong the
    // day the class is a singleton or recommended() gains a second caller.
    $state = array_filter(
        (new ReflectionClass(ImageTags::class))->getProperties(),
        fn (ReflectionProperty $property): bool => ! $property->isPromoted() && ! $property->isStatic(),
    );

    expect(array_map(fn (ReflectionProperty $p): string => $p->getName(), $state))->toBe([]);
});

it('lists GHCR tags from the registry, following pagination', function () {
    Http::fake(function (Request $r) {
        if (str_contains($r->url(), '/token')) {
            return Http::response(['token' => 't']);
        }

        if (! $r->hasHeader('Authorization')) {
            return Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io"']);
        }

        return str_contains($r->url(), 'last=')
            ? Http::response(['name' => 'usememos/memos', 'tags' => ['0.31.0', 'latest']])
            : Http::response(['name' => 'usememos/memos', 'tags' => ['0.12.1', '0.30.0', 'test']], 200, [
                'Link' => '</v2/usememos/memos/tags/list?last=test&n=1000>; rel="next"',
            ]);
    });

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=ghcr.io/usememos/memos&limit=3')->assertOk();

    expect($response->json('recommended'))->toBe('0.31.0')
        ->and(array_column($response->json('tags'), 'name'))->toBe(['0.31.0', '0.30.0', '0.12.1'])
        ->and($response->json('tags.0.updated_at'))->toBeNull();
});

it('reads LinuxServer tags from their Hub mirror, which carries dates', function () {
    Http::fake(['hub.docker.com/v2/repositories/linuxserver/heimdall/tags*' => Http::response(['results' => [
        ['name' => '2.8.3', 'last_updated' => '2026-09-25T00:00:00Z', 'tag_status' => 'active'],
    ]])]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=lscr.io/linuxserver/heimdall')
        ->assertOk()->assertJsonPath('recommended', '2.8.3');

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'ghcr.io'));
});

it('falls back to latest when nothing is a version, and marks an unreachable registry offline', function () {
    Http::fake(['hub.docker.com/*' => Http::response(['results' => [
        ['name' => 'edge', 'tag_status' => 'active'], ['name' => 'latest', 'tag_status' => 'active'],
    ]])]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=acme/rolling')
        ->assertOk()->assertJsonPath('recommended', 'latest');

    Http::fake(fn () => Http::failedConnection());

    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image=acme/other')
        ->assertOk()->assertExactJson(['image' => 'acme/other', 'recommended' => null, 'tags' => [], 'offline' => true]);
});

/*
 * Search.
 */

it('ranks search results official first, then by pulls', function () {
    Http::fake(['hub.docker.com/v2/search/repositories/*' => Http::response(['results' => [
        ['repo_name' => 'elestio/memos', 'short_description' => 'Repackaged', 'star_count' => 21, 'pull_count' => 102962, 'is_official' => false],
        ['repo_name' => 'neosmemo/memos', 'short_description' => 'A privacy-first note-taking service.', 'star_count' => 233, 'pull_count' => 11672941, 'is_official' => false],
        ['repo_name' => 'memos', 'short_description' => 'Imaginary official image', 'star_count' => 1, 'pull_count' => 5, 'is_official' => true],
    ]])]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=memos&limit=2')->assertOk();

    expect(array_column($response->json('results'), 'image'))->toBe(['memos', 'neosmemo/memos'])
        ->and($response->json('results.1'))->toBe([
            'image' => 'neosmemo/memos', 'registry' => 'docker.io', 'description' => 'A privacy-first note-taking service.',
            'stars' => 233, 'pulls' => 11672941, 'official' => false, 'verified_publisher' => false,
        ])
        ->and($response->json())->not->toHaveKey('offline');

    // Cached: the same search again asks Hub nothing.
    $this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=memos')->assertOk();
    Http::assertSentCount(1);
});

it('puts the exact name typed first, and the project\'s own publisher above a busier repackage', function () {
    // Hub's real answers on 2026-10-07, pull counts included.
    Http::fake([
        'hub.docker.com/v2/search/repositories/?query=umami*' => Http::response(['results' => [
            ['repo_name' => 'elestio/umami', 'pull_count' => 56242, 'is_official' => false],
            ['repo_name' => 'umamisoftware/umami', 'pull_count' => 180849, 'is_official' => false],
            ['repo_name' => 'pabloszx/umami', 'pull_count' => 605359, 'is_official' => false],
            ['repo_name' => 'umami/docker-caddy', 'pull_count' => 1647, 'is_official' => false],
        ]]),
        'hub.docker.com/v2/search/repositories/?query=linuxserver*' => Http::response(['results' => [
            ['repo_name' => 'linuxserver/radarr', 'pull_count' => 900000000, 'is_official' => false],
            ['repo_name' => 'linuxserver/heimdall', 'pull_count' => 50000000, 'is_official' => false],
        ]]),
    ]);

    $umami = $this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=umami')->assertOk();
    $heimdall = $this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=linuxserver/heimdall')->assertOk();

    // `umami/docker-caddy` has the name in its namespace only: no boost.
    expect(array_column($umami->json('results'), 'image'))->toBe(['umamisoftware/umami', 'pabloszx/umami', 'elestio/umami', 'umami/docker-caddy'])
        ->and($heimdall->json('results.0.image'))->toBe('linuxserver/heimdall');
});

it('never lets a matching publisher with almost no pulls outrank the image people use', function (string $query, array $results, string $first) {
    // Hub's real answers on 2026-10-07 (DS-09). Ranked publisher-first, each
    // of these preselected the near-empty repository, and the form deploys
    // the preselected one in one click.
    Http::fake(['hub.docker.com/v2/search/repositories/*' => Http::response(['results' => array_map(
        fn (array $row): array => ['repo_name' => $row[0], 'pull_count' => $row[1], 'is_official' => false],
        $results,
    )])]);

    $response = $this->withHeaders(imgAs())->getJson('/api/docker/images/search?q='.$query)->assertOk();

    expect($response->json('results.0.image'))->toBe($first);
})->with([
    'kavita' => ['kavita', [['kavitaatdesign/kavita', 57], ['kizaing/kavita', 4817680], ['jvmilazz0/kavita', 11860769], ['linuxserver/kavita', 809855]], 'jvmilazz0/kavita'],
    'shiori' => ['shiori', [['shioriapp/shiori', 483], ['shioriex/shiori', 140], ['nicholaswilde/shiori', 166639], ['radhifadlillah/shiori', 3639784]], 'radhifadlillah/shiori'],
    'jupyter' => ['jupyter', [['jupyter/cdn.jupyter.org', 1345], ['jupyter/tensorflow-notebook', 75390823], ['jupyter/scipy-notebook', 93201063]], 'jupyter/scipy-notebook'],
    // A registered look-alike well past the floor still needs a fifth of
    // the real image's pulls to win.
    'busy squatter' => ['memos', [['memosapp/memos', 1000000], ['neosmemo/memos', 11672941]], 'neosmemo/memos'],
]);

it('weights a matching publisher on a ramp, not a step (DS-12)', function (array $results, string $first) {
    // With a 10k floor, one pull either side of it decided the order: the
    // project's own image at 9,999 lost to an unrelated 10,001.
    Http::fake(['hub.docker.com/v2/search/repositories/*' => Http::response(['results' => array_map(
        fn (array $row): array => ['repo_name' => $row[0], 'pull_count' => $row[1], 'is_official' => false],
        $results,
    )])]);

    expect($this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=newapp')->assertOk()->json('results.0.image'))
        ->toBe($first);
})->with([
    'just under the old floor' => [[['someone/newapp', 10001], ['newapp/newapp', 9999]], 'newapp/newapp'],
    'just over it' => [[['someone/newapp', 10001], ['newapp/newapp', 10000]], 'newapp/newapp'],
    // Still nothing for a name registered and never used.
    'no pulls to speak of' => [[['newapp/newapp', 900], ['someone/newapp', 901]], 'someone/newapp'],
]);

it('weights the project, not every repository its publisher owns (DS-12)', function () {
    // Hub's real pull counts, 2026-10-08. Matched as a substring, the
    // renderer plugin took third place from promtail on a "grafana" search.
    Http::fake(['hub.docker.com/v2/search/repositories/*' => Http::response(['results' => [
        ['repo_name' => 'grafana/grafana-image-renderer', 'pull_count' => 899000000, 'is_official' => false],
        ['repo_name' => 'grafana/promtail', 'pull_count' => 2913000000, 'is_official' => false],
        ['repo_name' => 'grafana/grafana', 'pull_count' => 5347000000, 'is_official' => false],
    ]])]);

    expect(array_column($this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=grafana')->assertOk()->json('results'), 'image'))
        ->toBe(['grafana/grafana', 'grafana/promtail', 'grafana/grafana-image-renderer']);
});

it('returns an empty list marked offline when Hub cannot be reached', function () {
    Http::fake(fn () => Http::failedConnection());

    $this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=memos')
        ->assertOk()->assertExactJson(['results' => [], 'offline' => true]);
});

it('validates the search query', function () {
    Http::fake();

    $this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=m')->assertStatus(422)->assertJsonValidationErrors('q');
    $this->withHeaders(imgAs())->getJson('/api/docker/images/search')->assertStatus(422);

    Http::assertNothingSent();
});

/*
 * Authorization.
 */

function imgUserWith(array $grants): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'slug' => 'r'.uniqid()]);

    foreach ($grants as $name) {
        $role->permissions()->attach(Permission::where('name', $name)->sole()->id, ['view' => true, 'manage' => false]);
    }

    $user = User::factory()->create();
    $user->roles()->attach($role);

    return $user;
}

it('needs the application permission', function () {
    Http::fake();
    $nobody = imgUserWith([]);

    $this->withHeaders(imgAs($nobody))->getJson('/api/docker/images/search?q=memos')->assertForbidden();
    $this->withHeaders(imgAs($nobody))->getJson('/api/docker/images/tags?image=nginx')->assertForbidden();
    $this->withHeaders(imgAs($nobody))->getJson('/api/docker/images/inspect?image=nginx')->assertForbidden();

    Http::assertNothingSent();
});

it('lets an application viewer inspect, but use a stored credential only with registry view', function () {
    imgRegistry(['docker.io/library/nginx:1.27' => imgConfig(['ExposedPorts' => ['80/tcp' => []]])]);
    $credential = imgCredential(['registry' => 'docker.io']);

    $viewer = imgUserWith(['application']);
    $this->withHeaders(imgAs($viewer))->getJson('/api/docker/images/inspect?image=nginx:1.27')->assertOk();
    $this->withHeaders(imgAs($viewer))->getJson('/api/docker/images/inspect?image=nginx:1.27&registry_id='.$credential->id)->assertForbidden();

    // The guard remembers the first user it resolved within one test.
    $this->app['auth']->forgetGuards();
    $both = imgUserWith(['application', 'registry']);
    $this->withHeaders(imgAs($both))->getJson('/api/docker/images/inspect?image=nginx:1.27&registry_id='.$credential->id)->assertOk();
});

it('is not offered on a server that hosts no containers', function () {
    Http::fake();
    ServerCapability::query()->update(['stack' => 'lemp', 'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['php']]]);

    $this->withHeaders(imgAs())->getJson('/api/docker/images/search?q=memos')->assertStatus(409);

    Http::assertNothingSent();
});

/*
 * DS-08: what a user-chosen registry can make the panel do.
 */

/**
 * One registry answering anonymously, with a manifest naming `$configDigest`
 * and whatever `$blob` says for the blob request.
 */
function imgHostile(string $host, string $configDigest, Closure $blob): void
{
    Http::fake(function (Request $request) use ($host, $configDigest, $blob) {
        $url = parse_url($request->url());

        if ($url['host'] === $host && str_contains($url['path'], '/manifests/')) {
            $body = json_encode([
                'schemaVersion' => 2,
                'mediaType' => 'application/vnd.docker.distribution.manifest.v2+json',
                'config' => ['mediaType' => 'application/vnd.docker.container.image.v1+json', 'digest' => $configDigest, 'size' => 100],
                'layers' => [],
            ]);

            return Http::response($body, 200, ['Docker-Content-Digest' => 'sha256:'.hash('sha256', $body)]);
        }

        return $blob($request);
    });
}

it('refuses registry hosts curl reads as loopback and the resolver does not', function (string $image) {
    Http::fake();

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image='.urlencode($image))->assertStatus(422);
    $this->withHeaders(imgAs())->getJson('/api/docker/images/tags?image='.urlencode($image))->assertStatus(422);

    Http::assertNothingSent();
})->with(['0x7f.0.0.1/foo/bar', '127.0x1/foo/bar', '0x7f000001:443/foo/bar', '2130706433:443/foo/bar', '0177.0.0.1/foo/bar']);

it('does not connect to a registry name it cannot resolve itself', function () {
    // Unpinned, curl would resolve it on its own — and read spellings PHP does not.
    RemoteHost::resolveUsing(fn (string $host): array => []);
    Http::fake();

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=nowhere.example/acme/app:1')
        ->assertStatus(503)->assertJsonPath('reason', 'unreachable');

    Http::assertNothingSent();
});

it('refuses a redirect to a name that resolves to loopback, without connecting to it', function () {
    RemoteHost::resolveUsing(fn (string $host): array => $host === 'cdn.evil.example' ? ['127.0.0.1'] : ['203.0.113.10']);
    $config = json_encode(imgConfig(['ExposedPorts' => ['80/tcp' => (object) []]]));

    imgHostile('registry.example.com', 'sha256:'.hash('sha256', $config), fn () => Http::response('', 307, ['Location' => 'https://cdn.evil.example/blob']));

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=registry.example.com/acme/app:1')->assertStatus(422);

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'cdn.evil.example'));
});

it('follows a blob redirect to a CDN, without the registry token', function () {
    // Hub and GHCR both answer a blob request with a redirect to a CDN. The
    // registry's bearer token is for the registry; the CDN gets a signed URL.
    $config = json_encode(imgConfig(['ExposedPorts' => ['8080/tcp' => (object) []]]));
    $digest = 'sha256:'.hash('sha256', $config);
    $manifest = json_encode(['schemaVersion' => 2, 'mediaType' => 'application/vnd.docker.distribution.manifest.v2+json',
        'config' => ['mediaType' => 'application/vnd.docker.container.image.v1+json', 'digest' => $digest, 'size' => 100], 'layers' => []]);

    Http::fake(function (Request $r) use ($config, $manifest) {
        $url = parse_url($r->url());

        return match (true) {
            $url['host'] === 'cdn.example.net' => Http::response($config),
            $url['path'] === '/token' => Http::response(['token' => 'reg-token']),
            ! $r->hasHeader('Authorization') => Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://registry.example.com/token",service="registry.example.com"']),
            str_contains($url['path'], '/manifests/') => Http::response($manifest, 200, ['Docker-Content-Digest' => 'sha256:'.hash('sha256', $manifest)]),
            default => Http::response('', 307, ['Location' => 'https://cdn.example.net/blob?sig=1']),
        };
    });

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=registry.example.com/acme/app:1')
        ->assertOk()->assertJsonPath('suggested_port', 8080);

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/blobs/') && $r->hasHeader('Authorization', 'Bearer reg-token'));
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'cdn.example.net'));
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'cdn.example.net') && $r->hasHeader('Authorization'));
});

it('refuses a config blob that does not match its digest, and caches nothing for that digest', function () {
    // The review's poisoning: an attacker's registry names the digest of a
    // real image's config and serves its own blob under it. Whoever inspects
    // the real image next must not read the attacker's ports and volumes.
    $real = imgConfig(['ExposedPorts' => ['80/tcp' => (object) []], 'Volumes' => ['/usr/share/nginx/html' => (object) []]]);
    $realDigest = imgDigest(json_encode($real));
    $evil = json_encode(imgConfig(['ExposedPorts' => ['9999/tcp' => (object) []], 'Volumes' => ["/data\n      - /:/hostfs" => (object) []]]));

    imgHostile('attacker.example', $realDigest, fn () => Http::response($evil));

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=attacker.example/x:1')
        ->assertStatus(503)->assertJsonPath('reason', 'unreachable');

    expect(Cache::has('docker-image-config:attacker.example:'.$realDigest))->toBeFalse()
        ->and(Cache::has('docker-image-config:'.$realDigest))->toBeFalse();

});

it('refuses a manifest whose config digest is not a sha256 digest', function (string $digest) {
    imgHostile('registry.example.com', $digest, fn () => Http::response(json_encode(imgConfig([]))));

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=registry.example.com/acme/app:1')
        ->assertStatus(503)->assertJsonPath('reason', 'unreachable');

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/blobs/'));
})->with(['sha256:../../manifests/x', 'md5:abc', 'sha256:'.str_repeat('a', 64)."\n"]);

it('refuses an answer larger than any registry document', function () {
    config(['server.docker.images.max_response_bytes' => 4096]);
    $config = json_encode(imgConfig(['Env' => ['PAD='.str_repeat('x', 8000)]]));

    imgHostile('registry.example.com', 'sha256:'.hash('sha256', $config), fn () => Http::response($config));

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=registry.example.com/acme/app:1')
        ->assertStatus(503)->assertJsonPath('reason', 'unreachable');
});

it('throttles tags and inspect harder than search', function () {
    imgRegistry(['docker.io/library/nginx:1.27' => imgConfig([])]);

    foreach (range(1, 20) as $i) {
        $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=nginx:1.27')->assertOk();
    }

    $this->withHeaders(imgAs())->getJson('/api/docker/images/inspect?image=nginx:1.27')->assertStatus(429);
});
