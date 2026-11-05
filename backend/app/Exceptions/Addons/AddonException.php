<?php

namespace App\Exceptions\Addons;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An addon command that did not produce an answer, mapped to the status
 * Central branches on. Every body carries a machine `code`; the message is
 * translated for whoever reads it.
 *
 *   addon_not_installed      404  the binary is not on this server
 *   addon_licence_required   403  the addon says this server has not bought it
 *   addon_site_not_registered 409 Log Monitoring Suite has no record of this site yet
 *   addon_command_failed     422  the addon ran and refused (its own message)
 *   addon_bad_output         502  it answered something that is not its JSON
 *   addon_timed_out          504  it did not answer in time
 *   object_cache_redis_unavailable 409  this server's Redis cannot give a site
 *                                       an isolated account (not running,
 *                                       older than 6, or no password)
 *
 * Installing and removing an addon (AddonInstaller):
 *
 *   addon_busy                409  an install or remove of it is already running
 *   addon_signing_key_missing 503  no signing key is configured; nothing is installed unsigned
 *   addon_download_failed     502  the file could not be fetched, or was too large
 *   addon_checksum_mismatch   422  the file is not the one the SHA-256 names
 *   addon_signature_invalid   422  the signature does not verify against the key
 *   addon_not_executable      422  the file is not a Linux executable (ELF)
 *   addon_disk_low            422  too little free space for the addon's data
 *   addon_install_failed      500  a step on the server failed; everything was rolled back
 *   addon_remove_failed       500  a step of the removal failed
 */
class AddonException extends RuntimeException
{
    private const STATUS = [
        'addon_not_installed' => 404,
        'addon_licence_required' => 403,
        'addon_site_not_registered' => 409,
        'addon_command_failed' => 422,
        'addon_bad_output' => 502,
        'addon_timed_out' => 504,
        'object_cache_redis_unavailable' => 409,
        'addon_busy' => 409,
        'addon_signing_key_missing' => 503,
        'addon_download_failed' => 502,
        'addon_checksum_mismatch' => 422,
        'addon_signature_invalid' => 422,
        'addon_not_executable' => 422,
        'addon_disk_low' => 422,
        'addon_install_failed' => 500,
        'addon_remove_failed' => 500,
    ];

    /**
     * @param  array<string, mixed>  $details  the addon's own error fields, passed through
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function of(string $code, string $addon, ?string $addonMessage = null, array $details = []): self
    {
        $key = 'errors/addons.'.substr($code, strlen('addon_'));

        return new self($code, __($key, ['addon' => $addon, 'message' => (string) $addonMessage]), $details);
    }

    public function status(): int
    {
        return self::STATUS[$this->errorCode] ?? 500;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return array_merge($this->details === [] ? [] : ['addon' => $this->details], [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ]);
    }

    public function render(): JsonResponse
    {
        return response()->json($this->body(), $this->status());
    }
}
