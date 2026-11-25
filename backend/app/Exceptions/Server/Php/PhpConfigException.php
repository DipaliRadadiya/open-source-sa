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
        /** @var array<int, string> PHP's own words about what it refused */
        public readonly array $phpErrors = [],
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
     * The submitted configuration failed PHP's own validation and was rolled back: the user's
     * mistake, so 422 and no reference.
     */
    /**
     * @param  array<int, string>  $phpErrors  the lines PHP printed about it — which line, what is wrong
     */
    public static function invalid(string $version, array $phpErrors = []): self
    {
        return new self('errors/php.invalid_ini', 422, replace: ['version' => $version], phpErrors: $phpErrors);
    }

    /**
     * An `extension=/tmp/x.so` in php.ini is code the PHP-FPM master (root) loads at the next
     * reload, so extensions load from PHP's own extension directory only.
     */
    public static function extensionOutsideDirectory(string $line, string $directory): self
    {
        return new self('errors/php.ini_extension_outside_dir', 422, replace: ['line' => $line, 'directory' => $directory]);
    }

    /**
     * The change is on disk but PHP was not told to pick it up. Its own message, as "could not
     * be updated" would send someone to redo a change that already happened.
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
     * The toggle ran and the extension is still where it was. Its own message, so "Disabled"
     * is never reported for an extension that is still loaded.
     */
    public static function extensionUnchanged(string $version, string $extension, bool $enable, string $reference): self
    {
        return new self($enable ? 'errors/php.extension_not_enabled' : 'errors/php.extension_not_disabled', 422, $reference, ['extension' => $extension, 'version' => $version]);
    }

    /**
     * The PHP stack this server runs cannot do it; refusing beats a control that reports
     * success and changes nothing.
     */
    public static function unsupportedOnStack(string $stack): self
    {
        return new self('errors/php.unsupported_on_stack', 422, replace: ['stack' => $stack]);
    }

    /**
     * ionCube publishes no loader for this PHP version.
     *
     * 422 and no reference: nothing failed on the server, the combination does not exist
     * (the current archive starts at PHP 8.1).
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
     * Installed outside the panel (a php.ini line, or LiteSpeed's package). 422: the panel
     * declines to manage what it did not put there.
     */
    public static function ionCubeExternal(string $version): self
    {
        return new self('errors/php.ioncube_external', 422, replace: ['version' => $version]);
    }

    /** Installed by v7: Remove takes it out, and Install works after that. */
    public static function ionCubeV7(string $version): self
    {
        return new self('errors/php.ioncube_v7', 422, replace: ['version' => $version]);
    }

    public static function ionCubeDownloadFailed(string $reference): self
    {
        return new self('errors/php.ioncube_download_failed', 500, $reference);
    }

    /**
     * What arrived is not an ionCube loader for this machine.
     *
     * Its own message rather than a generic install failure: the download succeeded but the
     * contents were wrong (a checksum would catch it, but the vendor publishes none).
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
     * Validation failed and the original files were restored without a reload. A failed
     * restoration uses `ionCubeRollbackFailed()`.
     */
    public static function ionCubeConfigTestFailed(string $reference): self
    {
        return new self('errors/php.ioncube_config_test_failed', 500, $reference);
    }

    /**
     * Discovery is the safety check before an install. Failing here means what was already on
     * disk is unknown, so nothing was changed.
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
     * The reload failed, not the change: it may be on disk waiting for the next reload, and the
     * recovery files are still there.
     */
    public static function ionCubeReloadFailed(string $reference): self
    {
        return new self('errors/php.ioncube_reload_failed', 500, $reference);
    }

    /**
     * Recovery could not complete. Any original-file backups are retained (a fresh install has
     * none). Manual recovery is required; never report the change as safely undone.
     */
    public static function ionCubeRollbackFailed(string $reference): self
    {
        return new self('errors/php.ioncube_rollback_failed', 500, $reference);
    }

    /**
     * What went wrong, as a stable code (`ioncube_download_failed`, `reload_failed`) for a
     * caller that records the failure instead of rendering it.
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

        // PHP's own lines say where the file is wrong.
        if ($this->phpErrors !== []) {
            $payload['php_errors'] = $this->phpErrors;
        }

        return response()->json($payload, $this->status);
    }
}
