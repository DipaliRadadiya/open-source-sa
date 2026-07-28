<?php

namespace App\Exceptions\Server;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A failure talking to a git provider. Two distinct shapes, because the two
 * cases are the user's to fix in different ways:
 *
 *  - `invalidCredentials()` → 422, the token is wrong/expired/insufficient;
 *    the user retypes it. No reference — there is nothing to look up.
 *  - `unreachable()` → 502, the provider errored or timed out; the technical
 *    detail is in the server-ops log, correlated by `reference`.
 *
 * The upstream body is never echoed back — it can contain the token.
 */
class GitProviderException extends Exception
{
    private function __construct(
        private readonly string $messageKey,
        private readonly int $status,
        public readonly ?string $reference = null,
        /** @var array<string, mixed> */
        private readonly array $replace = [],
    ) {
        parent::__construct();
    }

    public static function invalidCredentials(string $provider): self
    {
        return new self('errors/git.invalid_credentials', 422, replace: ['provider' => $provider]);
    }

    public static function unreachable(string $provider, string $reference): self
    {
        return new self('errors/git.provider_unreachable', 502, $reference, ['provider' => $provider]);
    }

    public static function unsupportedProvider(string $provider): self
    {
        return new self('errors/git.unsupported_provider', 422, replace: ['provider' => $provider]);
    }

    public function render(Request $request): JsonResponse
    {
        $payload = ['message' => __($this->messageKey, $this->replace)];

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }

        return response()->json($payload, $this->status);
    }
}
