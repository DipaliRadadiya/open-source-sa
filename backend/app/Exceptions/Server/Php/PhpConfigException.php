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

    /**
     * The PHP stack this server runs has no way to do it. Refusing is the
     * honest answer — the alternative is a control that reports success and
     * changes nothing.
     */
    public static function unsupportedOnStack(string $stack): self
    {
        return new self('errors/php.unsupported_on_stack', 422, replace: ['stack' => $stack]);
    }

    /**
     * ionCube publishes no loader for this PHP version.
     *
     * 422 and no reference: nothing failed on the server, the combination
     * simply does not exist. PHP 8.0 is the live case — the panel still
     * offers it and the current archive starts at 8.1.
     */
    public static function ionCubeUnsupportedVersion(string $version): self
    {
        return new self('errors/php.ioncube_unsupported_version', 422, replace: ['version' => $version]);
    }

    public static function ionCubeUnsupportedArchitecture(string $architecture): self
    {
        return new self('errors/php.ioncube_unsupported_architecture', 422, replace: ['architecture' => $architecture]);
    }

    public static function ionCubeDownloadFailed(string $reference): self
    {
        return new self('errors/php.ioncube_download_failed', 500, $reference);
    }

    /**
     * What arrived is not an ionCube loader for this machine.
     *
     * Its own message rather than a generic install failure, because the
     * distinction matters to whoever reads it: the download succeeded and the
     * contents were wrong, which is the one case a checksum would have caught
     * if the vendor published one.
     */
    public static function ionCubeInvalidLoader(string $reference): self
    {
        return new self('errors/php.ioncube_invalid_loader', 500, $reference);
    }

    public static function ionCubeInstallFailed(string $reference): self
    {
        return new self('errors/php.ioncube_install_failed', 500, $reference);
    }

    /**
     * PHP refused to start with the loader in place, so it was taken back out
     * and nothing was reloaded. Separate from a plain install failure because
     * the user needs to know the server is still serving.
     */
    public static function ionCubeConfigTestFailed(string $reference): self
    {
        return new self('errors/php.ioncube_config_test_failed', 500, $reference);
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
