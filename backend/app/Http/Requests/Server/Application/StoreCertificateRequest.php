<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\CertificateType;
use App\Models\Application;
use App\Models\Certificate;
use App\Services\Server\Certificates\CertificateFiles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManage('app_domain');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $custom = 'type,'.CertificateType::Custom->value;

        return [
            'type' => ['required', Rule::enum(CertificateType::class)],

            // Only for an uploaded certificate. `required_if`, not `required_with`, so a stray key
            // pasted with a Let's Encrypt request is not quietly installed.
            'certificate' => ['nullable', 'required_if:'.$custom, 'string', 'starts_with:-----BEGIN'],
            'private_key' => ['nullable', 'required_if:'.$custom, 'string', 'starts_with:-----BEGIN'],
            'chain' => ['nullable', 'string', 'starts_with:-----BEGIN'],

            // Skips the reachability dry run, e.g. behind NAT where the dry run fails but the real
            // challenge would succeed. Not a default: the dry run protects the hourly authorisation limit.
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Check what was pasted before anything is written: a mismatched pair, a wrong-name or expired
     * certificate would otherwise replace a working one and break the site.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($validator->errors()->isNotEmpty() || $this->input('type') !== CertificateType::Custom->value) {
                    return;
                }

                $files = app(CertificateFiles::class);
                $pem = (string) $this->input('certificate');
                $parsed = @openssl_x509_parse($pem);

                // Checked first: a key pasted into this field would otherwise read as "key mismatch".
                if ($parsed === false) {
                    $validator->errors()->add('certificate', __('errors/certificate.not_certificate'));

                    return;
                }

                if (! $this->chainIsCertificates()) {
                    $validator->errors()->add('chain', __('errors/certificate.invalid_chain'));
                }

                if (($parsed['validTo_time_t'] ?? 0) <= now()->getTimestamp()) {
                    $validator->errors()->add('certificate', __('errors/certificate.expired'));
                } elseif (($parsed['validFrom_time_t'] ?? 0) > now()->getTimestamp()) {
                    $validator->errors()->add('certificate', __('errors/certificate.not_yet_valid'));
                }

                $this->checkCoverage($validator, $files->subjectNames($pem));

                if (! $files->keyMatchesCertificate($pem, (string) $this->input('private_key'))) {
                    $validator->errors()->add('private_key', __('errors/certificate.key_mismatch'));
                }
            },
        ];
    }

    /**
     * At least one name on the site must be on the certificate (a missing alias is reported
     * separately as `missing_domains`; covering none is never intended).
     *
     * @param  array<int, string>  $names
     */
    private function checkCoverage($validator, array $names): void
    {
        /** @var Application $application */
        $application = $this->route('application');
        $candidate = new Certificate(['domains' => $names]);
        $attached = $application->domains()->pluck('domain')->push($application->domain)->unique();

        if ($attached->contains(fn (string $domain): bool => $candidate->covers($domain))) {
            return;
        }

        $validator->errors()->add('certificate', __('errors/certificate.domain_not_covered', [
            'domains' => $attached->implode(', '),
        ]));
    }

    /**
     * An empty chain is fine. Anything else must be whole certificates and nothing between them:
     * OpenLiteSpeed silently drops a block it cannot parse.
     */
    private function chainIsCertificates(): bool
    {
        $chain = trim((string) $this->input('chain'));

        if ($chain === '') {
            return true;
        }

        $pattern = '/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s';

        if (! preg_match_all($pattern, $chain, $blocks) || trim((string) preg_replace($pattern, '', $chain)) !== '') {
            return false;
        }

        foreach ($blocks[0] as $block) {
            if (@openssl_x509_read($block) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The default `starts_with` message prints the raw PEM header; the usual cause is a DER
            // file or the wrong half of the pair.
            'certificate.starts_with' => __('errors/certificate.not_pem'),
            'private_key.starts_with' => __('errors/certificate.not_pem'),
            'chain.starts_with' => __('errors/certificate.not_pem'),
        ];
    }
}
