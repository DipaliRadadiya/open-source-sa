<?php

namespace App\Http\Requests\Server\StorageDestination;

use App\Rules\SafeProviderHost;
use App\Rules\SingleLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStorageDestinationRequest extends FormRequest
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
        $destination = $this->route('storageDestination');

        return [
            // Everything is partial-update. A missing field keeps what is
            // there now. Password-style fields keep the old value when
            // omitted so a simple rename doesn't lose the credentials.
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('storage_destinations', 'name')->ignore($destination),
                new SingleLine,
            ],

            // `nullable` because the service layer must accept an explicit
            // empty string to "clear" the column (matches the storage
            // driver's "use AWS defaults" sentinel). Tests of the create
            // path never omit `endpoint` — see StoreStorageDestinationRequest.
            'endpoint' => ['sometimes', 'string', 'max:255', new SafeProviderHost],
            'region' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'bucket' => ['sometimes', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]*$#'],

            // The presence of a key in the request means "rotate"; an
            // omission means "keep what is there". This matches how a
            // rotation form actually works (the user types new credentials,
            // leaves the rest alone) and keeps the controller from
            // accidentally clobbering the encrypted columns on a rename.
            'access_key' => ['sometimes', 'string', 'max:255'],
            'secret_key' => ['sometimes', 'string', 'max:512'],
        ];
    }
}
