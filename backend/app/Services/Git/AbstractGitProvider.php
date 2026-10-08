<?php

namespace App\Services\Git;

use App\Contracts\GitProvider;
use App\Exceptions\BlockedHostException;
use App\Exceptions\Server\GitProviderException;
use App\Models\GitAccount;
use App\Support\RemoteHost;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared outbound-HTTP hardening for every provider: bounded timeouts, capped
 * redirects, bearer auth (never a token in a URL or query string), and a
 * single place where upstream failures become translated exceptions.
 *
 * Nothing here ever logs the token or the raw response body.
 */
abstract class AbstractGitProvider implements GitProvider
{
    protected function client(GitAccount $account): PendingRequest
    {
        // Pinned to an address checked just now (bug #34): the account's host
        // was checked when it was saved, but a name can resolve to something
        // else later, and `127.0.0.1.nip.io` never looked like loopback at
        // all. A blocked address throws here, inside send(), and reaches the
        // user as "unreachable" with a reference.
        $pin = RemoteHost::pin($account->apiBaseUrl());

        return Http::withToken($account->token)
            ->acceptJson()
            ->baseUrl($account->apiBaseUrl())
            ->connectTimeout((int) config('server.git.connect_timeout', 3))
            ->timeout((int) config('server.git.timeout', 5))
            ->withOptions([
                ...($pin !== null ? ['curl' => [CURLOPT_RESOLVE => [$pin]]] : []),
                // A redirect is a new host, checked the same way.
                'allow_redirects' => [
                    'max' => (int) config('server.git.max_redirects', 3),
                    'protocols' => ['https'],
                    'on_redirect' => function ($request, $response, $uri): void {
                        if (RemoteHost::resolvesToBlocked($uri->getHost())) {
                            throw new BlockedHostException($uri->getHost());
                        }
                    },
                ],
            ]);
    }

    /**
     * Run a request and normalise the failure modes:
     *  401/403 → invalid or under-scoped credential (the user's to fix)
     *  anything else / transport error → unreachable, logged with a reference
     *
     * `$missingOk`: a 404 is an answer, not a failure, and comes back as the
     * response — for updating or removing a webhook somebody may already have
     * deleted by hand.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(GitAccount $account, callable $call, bool $missingOk = false): Response
    {
        $reference = (string) Str::uuid();

        try {
            $response = $call($this->client($account));
        } catch (Throwable $e) {
            Log::channel('server-ops')->error('git provider transport failure', [
                'reference' => $reference,
                'provider' => $this->key(),
                'account' => $account->label,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // FS-C39: a self-hosted address that does not answer at all is
            // most likely a wrong address — "try again in a moment" sent
            // people to wait for something waiting would not fix.
            if (filled($account->host)) {
                throw GitProviderException::hostUnreachable((string) parse_url((string) $account->host, PHP_URL_HOST) ?: (string) $account->host, $reference);
            }

            throw GitProviderException::unreachable($this->displayName(), $reference);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw GitProviderException::invalidCredentials($this->displayName());
        }

        if ($missingOk && $response->status() === 404) {
            return $response;
        }

        if ($response->failed()) {
            Log::channel('server-ops')->error('git provider request failed', [
                'reference' => $reference,
                'provider' => $this->key(),
                'account' => $account->label,
                'status' => $response->status(),
            ]);

            throw GitProviderException::unreachable($this->displayName(), $reference);
        }

        return $response;
    }

    /**
     * Make a request for a *status* check, where failure is information
     * rather than an error.
     *
     * Distinguishes the two failures that must not look alike in the UI: a
     * rejected credential (the user must act) versus a provider we could not
     * reach (nobody should act — a five-second outage must not paint every
     * account red).
     *
     * @param  callable(PendingRequest): Response  $call
     * @return array{status: 'valid'|'invalid'|'unknown', response: ?Response}
     */
    protected function probe(GitAccount $account, callable $call): array
    {
        try {
            return ['status' => 'valid', 'response' => $this->send($account, $call)];
        } catch (GitProviderException $e) {
            return [
                'status' => $e->kind === GitProviderException::INVALID_CREDENTIALS ? 'invalid' : 'unknown',
                'response' => null,
            ];
        }
    }

    /**
     * Parse a provider-supplied expiry into a date, tolerating whatever
     * format it uses. An unparseable value is simply "no expiry known" — it
     * must never turn a healthy account into an error.
     */
    protected function parseExpiry(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Repository shape shared by every provider — an explicit allow-list, so
     * no unexpected vendor field can travel further into the panel.
     *
     * @return array<string, mixed>
     */
    protected function repository(string $fullName, string $name, bool $private, ?string $defaultBranch, ?string $url): array
    {
        return [
            'full_name' => $fullName,
            'name' => $name,
            'private' => $private,
            'default_branch' => $defaultBranch,
            'url' => $url,
        ];
    }

    protected function perPage(): int
    {
        return (int) config('server.git.per_page', 30);
    }

    /**
     * The provider as people write it (FS-C38): "GitHub", not the `github`
     * id — "The github token was rejected" read like a typo.
     */
    protected function displayName(): string
    {
        return match ($this->key()) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
            default => ucfirst($this->key()),
        };
    }
}
