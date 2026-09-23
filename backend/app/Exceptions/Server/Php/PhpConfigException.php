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

    /**
     * The change is on disk but PHP was not told to pick it up, so it is not
     * active yet. Its own message: "could not be updated" would send someone
     * to redo a change that already happened.
     */
    public static function reloadFailed(string $version, string $reference): self
    {
        return new self('errors/php.reload_failed', 500, $reference, ['version' => $version]);
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

    /**
     * Installed outside the panel — v7's php.ini line, or LiteSpeed's
     * package. 422: nothing failed, the panel declines to manage what it did
     * not put there.
     */
    public static function ionCubeExternal(string $version): self
    {
        return new self('errors/php.ioncube_external', 422, replace: ['version' => $version]);
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
     * Validation failed and the original file state was restored without a
     * reload. This does not claim anything about the serving processes.
     * A failed restoration uses the separate rollback factory below.
     */
    public static function ionCubeConfigTestFailed(string $reference): self
    {
        return new self('errors/php.ioncube_config_test_failed', 500, $reference);
    }

    /**
     * Discovery is the safety check that decides whether an install is even
     * safe to attempt. Failing here means we could not be sure what was
     * already on disk, so nothing was changed — every subsequent step is
     * built on top of that "we know" answer.
     */
    public static function ionCubeDiscoveryFailed(string $reference): self
    {
        return new self('errors/php.ioncube_discovery_failed', 500, $reference);
    }

    public static function ionCubeExtractionFailed(string $reference): self
    {
        return new self('errors/php.ioncube_extraction_failed', 500, $reference);
    }

    public static function ionCubeRemovalFailed(string $reference): self
    {
        return new self('errors/php.ioncube_removal_failed', 500, $reference);
    }

    /**
     * The reload itself failed — not the change, but telling PHP to pick it
     * up. The change may still be on disk waiting for the next reload, and
     * the recovery files are still there in case it has to come back out.
     */
    public static function ionCubeReloadFailed(string $reference): self
    {
        return new self('errors/php.ioncube_reload_failed', 500, $reference);
    }

    /**
     * Recovery could not complete. Any original-file backups are retained;
     * a fresh install may have no originals to back up. Manual recovery is
     * required rather than reporting that the change was safely undone.
     */
    public static function ionCubeRollbackFailed(string $reference): self
    {
        return new self('errors/php.ioncube_rollback_failed', 500, $reference);
    }

    /**
     * What went wrong, as a stable code — `ioncube_download_failed`,
     * `reload_failed` — for a caller that records the failure instead of
     * rendering it, so the cause survives into the row the screen reads.
     */
    public function reason(): string
    {
        return str_replace('errors/php.', '', $this->messageKey);
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
