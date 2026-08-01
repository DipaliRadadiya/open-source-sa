<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\CertificateType;
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
        ];
    }

    /**
     * Check the pair before anything is written.
     *
     * A mismatched certificate and key are accepted happily by the filesystem,
     * fail the web server's config test, and take the site down over a
     * copy-paste. Catching it here means nothing has changed yet.
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

                $matches = app(CertificateFiles::class)->keyMatchesCertificate(
                    (string) $this->input('certificate'),
                    (string) $this->input('private_key'),
                );

                if (! $matches) {
                    $validator->errors()->add('private_key', __('errors/certificate.key_mismatch'));
                }
            },
        ];
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
