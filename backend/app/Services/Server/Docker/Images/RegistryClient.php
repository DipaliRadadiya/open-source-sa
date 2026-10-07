<?php

namespace App\Services\Server\Docker\Images;

use App\Exceptions\BlockedHostException;
use App\Models\Registry;
use App\Support\RemoteHost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Speaks the registry v2 API well enough to read an image without pulling it.
 *
 * Pulling would answer every question — and download gigabytes onto the box to
 * fill in a form field. The registry API gives the same config document
 * (`EXPOSE`, `VOLUME`, `ENV`, history) for two or three small requests.
 *
 * **Auth is negotiated, not assumed.** Every registry answers an anonymous
 * request with a `WWW-Authenticate` challenge naming its own token endpoint;
 * that challenge is followed rather than a per-registry table of token URLs,
 * so a self-hosted registry works without the panel having heard of it. A
 * stored credential is sent to the token endpoint as basic auth, and only when
 * it was saved for this image's registry (see {@see ImageReference::matches()}).
 *
 * **Outbound safety.** The registry host is typed by the user and the token
 * realm is named by the registry, so both are checked the way every other
 * outbound connection in the panel is: loopback and link-local refused, the
 * resolved address pinned so a second DNS answer cannot redirect the request,
 * and every redirect checked again. Blob downloads redirect to a CDN, so the
 * redirect check is not decoration.
 *
 * Measured on 2026-10-07 against Hub, GHCR and Quay: a missing tag is a `404`
 * with `MANIFEST_UNKNOWN` on all three, while a missing repository is a `401`
 * or `403` — indistinguishable, by design, from a private one.
 */
class RegistryClient
{
    public const MANIFEST_TYPES = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    /** @var array<string, string> bearer tokens for this request, by host|repository */
    private array $tokens = [];

    /**
     * A manifest (or manifest list) and the digest the registry gave it.
     *
     * @return array{manifest: array<string, mixed>, digest: string}
     */
    public function manifest(ImageReference $image, string $reference, ?Registry $credential): array
    {
        $response = $this->get($image, '/manifests/'.$reference, ['Accept' => implode(', ', self::MANIFEST_TYPES)], $credential);

        $manifest = $response->json();

        if (! is_array($manifest)) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'manifest is not JSON');
        }

        $digest = (string) $response->header('Docker-Content-Digest');

        if (preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1) {
            $digest = 'sha256:'.hash('sha256', $response->body());
        }

        return ['manifest' => $manifest, 'digest' => $digest];
    }

    /**
     * A JSON blob — in practice, the image config.
     *
     * @return array<string, mixed>
     */
    public function blob(ImageReference $image, string $digest, ?Registry $credential): array
    {
        $blob = $this->get($image, '/blobs/'.$digest, ['Accept' => '*/*'], $credential)->json();

        if (! is_array($blob)) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'config blob is not JSON');
        }

        return $blob;
    }

    /**
     * Every tag the registry lists, following its pagination a bounded number
     * of times. GHCR pages at a few hundred; an image with thousands of
     * `sha-` tags is not worth ten requests to fill a version picker.
     *
     * @return list<string>
     */
    public function tags(ImageReference $image, ?Registry $credential): array
    {
        $tags = [];
        $path = '/tags/list?n=1000';

        for ($page = 0; $page < (int) config('server.docker.images.max_tag_pages', 5) && $path !== null; $page++) {
            $response = $this->get($image, $path, ['Accept' => 'application/json'], $credential);

            foreach ((array) ($response->json('tags') ?? []) as $tag) {
                if (is_string($tag)) {
                    $tags[] = $tag;
                }
            }

            $path = $this->nextPage($image, (string) $response->header('Link'));
        }

        return $tags;
    }

    /**
     * A GET against the registry, negotiating auth on the first 401.
     *
     * @param  array<string, string>  $headers
     */
    private function get(ImageReference $image, string $path, array $headers, ?Registry $credential): Response
    {
        $url = 'https://'.$image->apiHost().'/v2/'.$image->repository.$path;
        $key = $image->apiHost().'|'.$image->repository;

        $response = $this->send($url, $headers + (isset($this->tokens[$key]) ? ['Authorization' => 'Bearer '.$this->tokens[$key]] : []));

        if ($response->status() === 401) {
            $authorization = $this->authorize($image, (string) $response->header('WWW-Authenticate'), $credential);

            if ($authorization !== null) {
                $response = $this->send($url, $headers + ['Authorization' => $authorization]);
            }
        }

        return $this->checked($response, $credential);
    }

    /**
     * Turn a challenge into an Authorization header, or null when there is
     * nothing to answer it with.
     */
    private function authorize(ImageReference $image, string $challenge, ?Registry $credential): ?string
    {
        if (preg_match('/^\s*Basic\b/i', $challenge) === 1) {
            return $credential !== null && $credential->hasCredentials()
                ? 'Basic '.base64_encode($credential->username.':'.(string) $credential->configValue('token', ''))
                : null;
        }

        if (preg_match('/^\s*Bearer\s+(.*)$/i', $challenge, $match) !== 1) {
            return null;
        }

        preg_match_all('/(\w+)="([^"]*)"/', $match[1], $pairs, PREG_SET_ORDER);
        $params = [];

        foreach ($pairs as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }

        $realm = $params['realm'] ?? '';

        // The realm is chosen by the registry, so it is an address the panel
        // connects to on somebody else's say-so. HTTPS only, and checked.
        if (! str_starts_with(strtolower($realm), 'https://')) {
            return null;
        }

        $query = array_filter([
            'service' => $params['service'] ?? null,
            'scope' => 'repository:'.$image->repository.':pull',
        ]);

        $request = $this->client();

        if ($credential !== null && $credential->hasCredentials()) {
            $request = $request->withBasicAuth((string) $credential->username, (string) $credential->configValue('token', ''));
        }

        try {
            $response = $request->withOptions($this->pinned($realm))->get($realm, $query);
        } catch (ConnectionException $e) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'token endpoint: '.$e->getMessage());
        } catch (BlockedHostException $e) {
            throw new ImageLookupException(ImageLookupException::BLOCKED_HOST, $e->getMessage());
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw $this->denied($credential);
        }

        if (! $response->successful()) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'token endpoint answered '.$response->status());
        }

        $token = (string) ($response->json('token') ?? $response->json('access_token') ?? '');

        if ($token === '') {
            return null;
        }

        $this->tokens[$image->apiHost().'|'.$image->repository] = $token;

        return 'Bearer '.$token;
    }

    /**
     * Sort a final answer into success or a reason.
     */
    private function checked(Response $response, ?Registry $credential): Response
    {
        $status = $response->status();

        if ($response->successful()) {
            return $response;
        }

        if ($status === 404) {
            $code = (string) ($response->json('errors.0.code') ?? '');

            throw new ImageLookupException(
                $code === 'NAME_UNKNOWN' ? ImageLookupException::NOT_FOUND : ImageLookupException::TAG_NOT_FOUND,
                $code,
            );
        }

        if (in_array($status, [401, 403], true)) {
            throw $this->denied($credential);
        }

        if ($status === 429) {
            throw new ImageLookupException(ImageLookupException::RATE_LIMITED);
        }

        throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'registry answered '.$status);
    }

    /**
     * A refusal means "no such image" when we sent nothing, and "your
     * credential cannot read it" when we did. The registry cannot tell us
     * which repository exists, so neither can we.
     */
    private function denied(?Registry $credential): ImageLookupException
    {
        return new ImageLookupException(
            $credential !== null && $credential->hasCredentials()
                ? ImageLookupException::CREDENTIAL_REJECTED
                : ImageLookupException::NOT_FOUND,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function send(string $url, array $headers): Response
    {
        try {
            return $this->client()->withHeaders($headers)->withOptions($this->pinned($url))->get($url);
        } catch (ConnectionException $e) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, $e->getMessage());
        } catch (BlockedHostException $e) {
            // Thrown by the redirect check, mid-request.
            throw new ImageLookupException(ImageLookupException::BLOCKED_HOST, $e->getMessage());
        }
    }

    private function client(): PendingRequest
    {
        return Http::connectTimeout((int) config('server.docker.images.connect_timeout', 5))
            ->timeout((int) config('server.docker.images.timeout', 10))
            // Not a product name: it is sent to third-party registries, and the
            // panel is white-labelled.
            ->withUserAgent('control-panel');
    }

    /**
     * Guzzle options pinning the host to a checked address, and checking any
     * redirect the same way. A blocked host is a refusal, not a network error.
     *
     * @return array<string, mixed>
     */
    private function pinned(string $url): array
    {
        try {
            $pin = RemoteHost::pin($url);
        } catch (BlockedHostException $e) {
            throw new ImageLookupException(ImageLookupException::BLOCKED_HOST, $e->getMessage());
        }

        return [
            ...($pin !== null ? ['curl' => [CURLOPT_RESOLVE => [$pin]]] : []),
            'allow_redirects' => [
                'max' => 3,
                'protocols' => ['https'],
                'on_redirect' => function ($request, $response, $uri): void {
                    if (RemoteHost::resolvesToBlocked($uri->getHost())) {
                        throw new BlockedHostException($uri->getHost());
                    }
                },
            ],
        ];
    }

    /**
     * The next page from an RFC 5988 `Link` header, kept on this registry.
     */
    private function nextPage(ImageReference $image, string $link): ?string
    {
        if (preg_match('/<([^>]+)>\s*;\s*rel="?next"?/', $link, $match) !== 1) {
            return null;
        }

        $prefix = '/v2/'.$image->repository;
        $target = (string) preg_replace('#^https?://[^/]+#', '', $match[1]);

        return str_starts_with($target, $prefix.'/tags/list') ? substr($target, strlen($prefix)) : null;
    }
}
