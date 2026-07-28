<?php

namespace App\Services\Git;

use App\Contracts\GitProvider;
use App\Exceptions\Server\GitProviderException;
use App\Models\GitAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
        return Http::withToken($account->token)
            ->acceptJson()
            ->baseUrl($account->apiBaseUrl())
            ->connectTimeout((int) config('server.git.connect_timeout', 3))
            ->timeout((int) config('server.git.timeout', 5))
            ->maxRedirects((int) config('server.git.max_redirects', 3));
    }

    /**
     * Run a request and normalise the failure modes:
     *  401/403 → invalid or under-scoped credential (the user's to fix)
     *  anything else / transport error → unreachable, logged with a reference
     *
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(GitAccount $account, callable $call): Response
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

            throw GitProviderException::unreachable($this->key(), $reference);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw GitProviderException::invalidCredentials($this->key());
        }

        if ($response->failed()) {
            Log::channel('server-ops')->error('git provider request failed', [
                'reference' => $reference,
                'provider' => $this->key(),
                'account' => $account->label,
                'status' => $response->status(),
            ]);

            throw GitProviderException::unreachable($this->key(), $reference);
        }

        return $response;
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
}
