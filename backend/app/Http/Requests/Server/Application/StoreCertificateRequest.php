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

            // Only for an uploaded certificate. `required_if` rather than
            // `required_with` so asking for a Let's Encrypt certificate and
            // pasting a stray key does not quietly install the key.
            'certificate' => ['nullable', 'required_if:'.$custom, 'string', 'starts_with:-----BEGIN'],
            'private_key' => ['nullable', 'required_if:'.$custom, 'string', 'starts_with:-----BEGIN'],
            'chain' => ['nullable', 'string', 'starts_with:-----BEGIN'],

            // Skip the reachability dry run. One legitimate case: a server
            // behind NAT whose public address does not answer to itself, where
            // the dry run fails but the real challenge — which arrives from
            // outside — would succeed. Not a default, because the thing it
            // skips is what stops a doomed attempt spending one of five
            // authorisation failures an hour.
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Check what was pasted before anything is written.
     *
     * Each of these used to be accepted, marked active, and put in front of a
     * working certificate: a mismatched pair takes the site down over a
     * copy-paste; a certificate for another name or one that has expired is
     * served and every visitor gets a browser error. Catching it here means
     * nothing has changed yet.
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

                // Checked on its own first. A key pasted into the certificate
                // field otherwise reads as "the key does not match", which sends
                // the user looking at the wrong field.
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
     * At least one name on the site must be on the certificate.
     *
     * Not all of them: a certificate that misses an alias is a real setup, and
     * `missing_domains` already reports it. One that covers none of them is
     * never what anybody meant.
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
     * An empty chain is fine. Anything else must be one or more whole
     * certificates and nothing between them: OpenLiteSpeed quietly drops a
     * block it cannot parse, and the site then fails on every client without
     * a cached intermediate.
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
            // The default `starts_with` message would print the literal PEM
            // header at the user, which reads as noise. What went wrong is
            // almost always that they pasted a DER file or the wrong half of
            // the pair, and the message should say so.
            'certificate.starts_with' => __('errors/certificate.not_pem'),
            'private_key.starts_with' => __('errors/certificate.not_pem'),
            'chain.starts_with' => __('errors/certificate.not_pem'),
        ];
    }
}
