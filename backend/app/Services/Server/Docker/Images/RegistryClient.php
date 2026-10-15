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
 * and every redirect checked and pinned again. Blob downloads redirect to a
 * CDN, so the redirect check is not decoration.
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

        // A blob IS its digest. One whose bytes hash to something else is not
        // the blob that was asked for, whatever the registry says — and its
        // answer is cached under that digest, so believing it would hand it to
        // everyone who asks for the real one.
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

        // Not redirected: a token endpoint that sends the credential elsewhere
        // is not one to follow.
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
     * A GET, following at most three redirects by hand.
     *
     * By hand because each hop is a new host — blob downloads go to a CDN — and
     * each host has to be checked AND pinned before it is connected to. Guzzle's
     * own redirect hook can only check: the connection that follows resolves
     * the name again, which is exactly the second answer pinning exists to
     * stop. The registry's token never follows a redirect to another host.
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
            // Only when there is one: an empty `query` option replaces the
            // query string already in the URL — the next page of tags.
            $request = $request->withOptions($this->pinned($url));
            $response = $query === [] ? $request->get($url) : $request->get($url, $query);
        } catch (ConnectionException|RequestException|TransferException $e) {
            // A transfer cut off by the size cap lands here too.
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, $e->getMessage());
        }

        // The transfer is cut off at the cap already (see `client()`); this is
        // the check that holds whatever delivered the body.
        if (strlen($response->body()) > $this->maxBytes()) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, 'response too large');
        }

        return $response;
    }

    /**
     * Far past any registry document the panel reads — a config blob is tens of
     * KB, a page of a thousand tags well under one MB.
     */
    private function maxBytes(): int
    {
        return (int) config('server.docker.images.max_response_bytes', 2 * 1024 * 1024);
    }

    /**
     * Every request made here: short timeouts, and a transfer cut off once it
     * passes the size cap. The registry is chosen by the user, and every answer
     * is decoded whole, so one that streamed for the full timeout would fill the
     * worker's memory.
     */
    private function client(): PendingRequest
    {
        $max = $this->maxBytes();

        return Http::connectTimeout((int) config('server.docker.images.connect_timeout', 5))
            ->timeout((int) config('server.docker.images.timeout', 10))
            // Not a product name: it is sent to third-party registries, and the
            // panel is white-labelled.
            ->withUserAgent('control-panel')
            ->withOptions(['curl' => [
                CURLOPT_NOPROGRESS => false,
                // Non-zero aborts the transfer. Counted as it arrives, because a
                // chunked body declares no length.
                CURLOPT_XFERINFOFUNCTION => fn ($handle, int $total, int $received): int => ($total > $max || $received > $max) ? 1 : 0,
            ]]);
    }

    /**
     * Guzzle options pinning the host to an address that was just checked.
     *
     * Fails closed. A host the panel will not interpret is refused, and so is a
     * name it cannot resolve itself: curl reads some spellings PHP's resolver
     * does not — `0x7f.0.0.1` is nothing to `gethostbynamel()` and 127.0.0.1 to
     * curl — so an unpinned name is a name whose address nobody checked.
     * Redirects are never followed here; `send()` follows them one pinned hop
     * at a time.
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
