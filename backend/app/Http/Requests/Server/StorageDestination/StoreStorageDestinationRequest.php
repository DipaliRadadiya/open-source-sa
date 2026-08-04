<?php

namespace App\Http\Requests\Server\StorageDestination;

use App\Rules\SafeProviderHost;
use App\Rules\SingleLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStorageDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('storage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('storage_destinations', 'name'), new SingleLine],

            // Endpoint is optional: S3-compatible providers (AWS, R2, B2,
            // Wasabi, …) carry their region inside the bucket host. When
            // set, it must be a reachable https URL and must not point at
            // loopback or the cloud metadata range — the same SSRF surface
            // Self-hosted GitLab exposes, reused here.
            'endpoint' => ['nullable', 'string', 'max:255', new SafeProviderHost(
                'storage.test.invalid_endpoint',
                'storage.test.forbidden_host',
            )],

            'region' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'bucket' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'prefix' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]*$#'],

            // Secrets — encrypted on write (model cast) and never echoed
            // back through the API. Sizes are generous but capped to keep
            // a stray paste of a megabyte log from filling the database.
            'access_key' => ['required', 'string', 'max:255'],
            'secret_key' => ['required', 'string', 'max:512'],
        ];
    }

    /**
     * Pre-fill defaults so the frontend can send a slim payload.
     *
     * `endpoint` defaults to an empty string rather than null because the
     * column is not nullable (an endpoint URL is part of the record), and
     * the empty-string sentinel lets the storage driver fall back to AWS
     * defaults while the DB still sees a non-null value.
     */
    protected function prepareForValidation(): void
    {
        $region = $this->input('region');
        $endpoint = $this->input('endpoint');

        $this->merge([
            'region' => $region === null || $region === '' ? 'us-east-1' : $region,
            'endpoint' => $endpoint === null ? '' : $endpoint,
        ]);
    }
}
