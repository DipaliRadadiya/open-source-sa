<?php

namespace App\Http\Requests\Server\StorageDestination;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Rules\SingleLine;
use App\Services\Server\Backups\Storage\SshPrivateKeyCheck;
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
            // Partial update: a missing field keeps its current value, so a rename does not
            // lose credentials.
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('storage_destinations', 'name')->ignore($destination),
                new SingleLine,
            ],

            // `..` walks out of the bucket or folder the destination names; refused as the
            // SFTP/FTP root already is.
            'prefix' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]*$#', 'not_regex:#(^|/)\.\.(/|$)#'],

            // Immutable: the `config` shape is defined by the provider, so a change would
            // reinterpret a stored bucket and secret key as a hostname and password. Refused
            // rather than ignored, so a 200 never suggests it changed.
            'provider' => ['prohibited'],
        ], $this->providerRules());
    }

    /**
     * On update the driver's secrets stop being required ("omission means keep"); every other
     * rule still applies.
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

        // Every config key becomes optional on a partial update. `sometimes` skips the rest of
        // the chain when the key is absent, so a leftover `required` would reject a rename.
        return array_map(
            fn (array $rule): array => $this->optional($rule),
            $rules,
        );
    }

    /**
     * @param  array<int, mixed>  $rule
     * @return array<int, mixed>
     */
    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator): void {
            $destination = $this->destination();

            if ($destination?->provider !== StorageProvider::Sftp || ! filled($this->input('config.private_key'))) {
                return;
            }

            // A passphrase sent now wins; otherwise the stored one, so
            // rotating only the key of a protected pair is not refused.
            $passphrase = $this->input('config.passphrase') ?? $destination->configValue('passphrase');
            $problem = SshPrivateKeyCheck::problem((string) $this->input('config.private_key'), $passphrase);

            if ($problem !== null) {
                $validator->errors()->add('config.private_key', __($problem));
            }
        });
    }

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
