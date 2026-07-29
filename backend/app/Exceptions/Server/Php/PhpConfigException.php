<?php

namespace App\Exceptions\Server\Php;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhpConfigException extends Exception
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

    public static function unknownVersion(string $version): self
    {
        return new self('errors/php.unknown_version', 404, replace: ['version' => $version]);
    }

    public static function unreadable(string $version, string $reference): self
    {
        return new self('errors/php.unreadable', 500, $reference, ['version' => $version]);
    }

    /**
     * The submitted configuration failed PHP's own validation, so it was
     * rolled back. The user's mistake to fix, hence 422 and no reference.
     */
    public static function invalid(string $version): self
    {
        return new self('errors/php.invalid_ini', 422, replace: ['version' => $version]);
    }

    public static function operationFailed(string $version, string $reference): self
    {
        return new self('errors/php.operation_failed', 500, $reference, ['version' => $version]);
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
