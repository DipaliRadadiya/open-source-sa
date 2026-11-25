<?php

namespace App\Services\Server\Docker\Images;

use App\Exceptions\BlockedHostException;
use App\Models\Registry;
use App\Support\RemoteHost;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Speaks the registry v2 API well enough to read an image without pulling it.
 *
 * The registry API gives the image config (`EXPOSE`, `VOLUME`, `ENV`, history) in two or three
 * small requests, without pulling gigabytes.
 *
 * Auth is negotiated: the `WWW-Authenticate` challenge names the token endpoint, so a self-hosted
 * registry works with no per-registry table. A stored credential is sent to the token endpoint
 * as basic auth, only when saved for this image's registry (see {@see ImageReference::matches()}).
 *
 * Outbound safety: the registry host is typed by the user and the token realm is named by the
 * registry, so both get the usual checks: loopback and link-local refused, the resolved address
 * pinned (no second DNS answer), and every redirect (blobs redirect to a CDN) checked and pinned.
 *
 * A missing tag is a `404` with `MANIFEST_UNKNOWN` on Hub, GHCR and Quay; a missing repository is
 * a `401` or `403`, indistinguishable from a private one.
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

        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
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
        $response = $this->get($image, '/blobs/'.$digest, ['Accept' => '*/*'], $credential);

        // A blob is its digest. Bytes that hash differently are rejected: the answer is cached
        // under that digest and would be served to everyone asking for the real one.
        if (! hash_equals($digest, 'sha256:'.hash('sha256', $response->body()))) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'config blob does not match its digest');
        }

        $blob = $response->json();

        if (! is_array($blob)) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'config blob is not JSON');
        }

        return $blob;
    }

    /**
     * Every tag the registry lists, following pagination a bounded number of times (an image with
     * thousands of `sha-` tags is not worth ten requests).
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
     * Turn a challenge into an Authorization header, or null when there is nothing to answer it.
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

        // The realm is chosen by the registry, so the panel connects on somebody else's say-so:
        // HTTPS only, and checked.
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

        // Not redirected: never forward the credential elsewhere.
        $response = $this->fetch($request, $realm, $query);

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
     * A refusal means "no such image" when we sent no credential, "credential cannot read it" when
     * we did; registries do not reveal which repositories exist.
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
     * A GET, following at most three redirects by hand.
     *
     * By hand because each hop (blobs go to a CDN) must be checked and pinned before connecting;
     * Guzzle's redirect hook can only check, and the connection then resolves the name again. The
     * registry's token is dropped on a redirect to another host.
     *
     * @param  array<string, string>  $headers
     */
    private function send(string $url, array $headers): Response
    {
        $origin = strtolower((string) parse_url($url, PHP_URL_HOST));

        for ($hop = 0; ; $hop++) {
            $response = $this->fetch($this->client()->withHeaders($headers), $url);
            $location = (string) $response->header('Location');

            if (! $response->redirect() || $location === '') {
                return $response;
            }

            if ($hop >= 3) {
                throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'too many redirects');
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

            if (! str_starts_with(strtolower($url), 'https://')) {
                throw new ImageLookupException(ImageLookupException::BLOCKED_HOST, 'redirect away from https');
            }

            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $origin) {
                unset($headers['Authorization']);
            }
        }
    }

    /**
     * One pinned, size-capped GET, with every transport failure turned into a
     * lookup failure.
     *
     * @param  array<string, mixed>  $query
     */
    private function fetch(PendingRequest $request, string $url, array $query = []): Response
    {
        try {
            // Only pass a query when there is one: an empty one replaces the URL's own query string.
            $request = $request->withOptions($this->pinned($url));
            $response = $query === [] ? $request->get($url) : $request->get($url, $query);
        } catch (ConnectionException|RequestException|TransferException $e) {
            // A transfer cut off by the size cap lands here too.
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, $e->getMessage());
        }

        // `client()` already cuts the transfer at the cap; this holds whatever delivered the body.
        if (strlen($response->body()) > $this->maxBytes()) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'response too large');
        }

        return $response;
    }

    /**
     * Far past any registry document the panel reads (a config blob is tens of KB).
     */
    private function maxBytes(): int
    {
        return (int) config('server.docker.images.max_response_bytes', 2 * 1024 * 1024);
    }

    /**
     * Every request made here: short timeouts, and a transfer cut off at the size cap. The registry
     * is user-chosen and every answer is decoded whole, so a long stream would fill worker memory.
     */
    private function client(): PendingRequest
    {
        $max = $this->maxBytes();

        return Http::connectTimeout((int) config('server.docker.images.connect_timeout', 5))
            ->timeout((int) config('server.docker.images.timeout', 10))
            // Not a product name: sent to third-party registries, and the panel is white-labelled.
            ->withUserAgent('control-panel')
            ->withOptions(['curl' => [
                CURLOPT_NOPROGRESS => false,
                // Non-zero aborts the transfer; counted as it arrives (chunked bodies declare no length).
                CURLOPT_XFERINFOFUNCTION => fn ($handle, int $total, int $received): int => ($total > $max || $received > $max) ? 1 : 0,
            ]]);
    }

    /**
     * Guzzle options pinning the host to an address that was just checked.
     *
     * Fails closed: a host the panel will not interpret is refused, and so is a name it cannot
     * resolve itself (`0x7f.0.0.1` is nothing to `gethostbynamel()` but 127.0.0.1 to curl), since an
     * unpinned name is one whose address nobody checked. Redirects are followed only by `send()`.
     *
     * @return array<string, mixed>
     */
    private function pinned(string $url): array
    {
        $host = RemoteHost::canonical((string) parse_url($url, PHP_URL_HOST));

        if (RemoteHost::isUninterpretable($host)) {
            throw new ImageLookupException(ImageLookupException::BLOCKED_HOST, $host);
        }

        try {
            $pin = RemoteHost::pin($url);
        } catch (BlockedHostException $e) {
            throw new ImageLookupException(ImageLookupException::BLOCKED_HOST, $e->getMessage());
        }

        if ($pin === null && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'host does not resolve: '.$host);
        }

        return [
            ...($pin !== null ? ['curl' => [CURLOPT_RESOLVE => [$pin]]] : []),
            'allow_redirects' => false,
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
