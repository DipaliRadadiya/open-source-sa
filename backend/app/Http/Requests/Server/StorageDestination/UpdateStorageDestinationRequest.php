<?php

namespace App\Http\Requests\Server\StorageDestination;

use App\Models\StorageDestination;
use App\Rules\SingleLine;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
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
        $destination = $this->destination();

        return array_merge([
            // Everything is partial-update. A missing field keeps what is
            // there now. Credential fields keep the old value when omitted so
            // a simple rename doesn't lose them.
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('storage_destinations', 'name')->ignore($destination),
                new SingleLine,
            ],

            'prefix' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]*$#'],

            // Immutable. The `config` blob's *shape* is defined by the
            // provider, so changing the provider would reinterpret a stored
            // bucket and secret key as a hostname and a password. Refused
            // outright rather than ignored: a request that asked to change it
            // and got a 200 would reasonably believe it had.
            'provider' => ['prohibited'],
        ], $this->providerRules());
    }

    /**
     * On update the driver's secrets stop being required — that is the whole
     * "omission means keep" contract. Everything else it asks for still
     * applies, because a bucket name that was valid on create is not allowed
     * to become invalid on rename.
     *
     * @return array<string, mixed>
     */
    protected function providerRules(): array
    {
        $destination = $this->destination();

        if (! $destination instanceof StorageDestination) {
            return [];
        }

        $rules = app(StorageDriverFactory::class)
            ->for($destination)
            ->rules(requireSecrets: false);

        // Every config key becomes optional on a partial update: the caller
        // may be sending only `config.host`. `sometimes` short-circuits the
        // rest of the chain when the key is absent, so a `required` left in
        // place would otherwise reject a rename for not resending a bucket.
        return array_map(
            fn (array $rule): array => $this->optional($rule),
            $rules,
        );
    }

    /**
     * @param  array<int, mixed>  $rule
     * @return array<int, mixed>
     */
    private function optional(array $rule): array
    {
        $rule = array_values(array_filter(
            $rule,
            fn ($r): bool => $r !== 'required' && $r !== 'sometimes',
        ));

        return ['sometimes', ...$rule];
    }

    private function destination(): ?StorageDestination
    {
        $destination = $this->route('storageDestination');

        return $destination instanceof StorageDestination ? $destination : null;
    }
}
