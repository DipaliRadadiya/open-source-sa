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
 *   addon_site_not_registered 409 InsightHub has no record of this site yet
 *   addon_command_failed     422  the addon ran and refused (its own message)
 *   addon_bad_output         502  it answered something that is not its JSON
 *   addon_timed_out          504  it did not answer in time
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
