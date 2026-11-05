<?php

namespace App\Http\Requests\Server\Addons;

use App\Services\Addons\AddonPackage;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What Central sends to install or update an addon: where its binary is, and
 * the checksum and signature that prove it is Central's.
 *
 * The URL must be https on Central's own host (config `central.url`). The
 * signature is checked against the server's key when the file is in hand;
 * here it is only checked to be 64 bytes of base64.
 */
class InstallAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('central_authenticated') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9.+_-]*$/'],
            'url' => ['required', 'string', 'max:2048', 'url', $this->onCentral(...)],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            // Optional only in test mode (AddonPackage::signatureRequired()).
            'signature' => [AddonPackage::signatureRequired() ? 'required' : 'nullable', 'string', 'max:128', $this->ed25519Signature(...)],
            // How long access logs are kept. Log Monitoring Suite only.
            'retention_days' => [
                Rule::prohibitedIf($this->route('addon') !== 'log-monitoring-suite'),
                'nullable', 'integer', Rule::in((array) config('server.addons.log_monitoring_suite.retention_days')),
            ],
        ];
    }

    private function onCentral(string $attribute, mixed $value, Closure $fail): void
    {
        $central = strtolower((string) parse_url((string) config('central.url'), PHP_URL_HOST));
        $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

        if ($central === '' || $host !== $central || strtolower((string) parse_url((string) $value, PHP_URL_SCHEME)) !== 'https') {
            $fail(__('errors/addons.url_not_central'));
        }
    }

    private function ed25519Signature(string $attribute, mixed $value, Closure $fail): void
    {
        $decoded = base64_decode((string) $value, true);

        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES) {
            $fail(__('errors/addons.signature_malformed'));
        }
    }
}
