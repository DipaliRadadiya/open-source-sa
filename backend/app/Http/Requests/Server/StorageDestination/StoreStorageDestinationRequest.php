<?php

namespace App\Http\Requests\Server\StorageDestination;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Rules\SingleLine;
use App\Services\Integrations\DuplicateIntegration;
use App\Services\Server\Backups\Storage\SshPrivateKeyCheck;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStorageDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('storage') ?? false;
    }

    /**
     * Provider-independent rules, plus the `config.*` rules the chosen provider's driver supplies.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // This exact credential is already a destination: the existing one is returned, so neither
        // its name nor the provider's own checks (e.g. a local folder "already in use") may refuse it.
        if ($this->existingRecord() !== null) {
            return [
                'name' => ['required', 'string', 'max:100', new SingleLine],
                'provider' => ['required', Rule::enum(StorageProvider::class)],
            ];
        }

        return array_merge([
            'name' => ['required', 'string', 'max:100', Rule::unique('storage_destinations', 'name'), new SingleLine],

            // No default: a missing provider must fail, not silently become 's3'.
            'provider' => ['required', Rule::enum(StorageProvider::class)],

            // Where inside the destination's space files land (S3 key prefix, FTP subdirectory).
            // `..` would walk out of the bucket or folder, so it is refused.
            'prefix' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]*$#', 'not_regex:#(^|/)\.\.(/|$)#'],
        ], $this->providerRules());
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerRules(): array
    {
        $provider = StorageProvider::tryFrom((string) $this->input('provider'));

        // An unknown provider already fails the enum rule; no second error about its config keys.
        if ($provider === null) {
            return [];
        }

        return app(StorageDriverFactory::class)->forProvider($provider)->rules(requireSecrets: true);
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->existingRecord() !== null) {
                return;
            }

            $this->validateSftpHasOneAuthMethod($validator);
            $this->validateSftpPrivateKey($validator);
        });
    }

    /**
     * SFTP needs a password or a private key; neither is `required` alone, but one must be present.
     */
    protected function validateSftpHasOneAuthMethod(mixed $validator): void
    {
        if (StorageProvider::tryFrom((string) $this->input('provider')) !== StorageProvider::Sftp) {
            return;
        }

        if (filled($this->input('config.password')) || filled($this->input('config.private_key'))) {
            return;
        }

        $validator->errors()->add('config.password', __('storage.validation.sftp_auth_required'));
    }

    protected function validateSftpPrivateKey(mixed $validator): void
    {
        if (StorageProvider::tryFrom((string) $this->input('provider')) !== StorageProvider::Sftp
            || ! filled($this->input('config.private_key'))) {
            return;
        }

        $problem = SshPrivateKeyCheck::problem(
            (string) $this->input('config.private_key'),
            $this->input('config.passphrase'),
        );

        if ($problem !== null) {
            $validator->errors()->add('config.private_key', __($problem));
        }
    }

    /**
     * Fills gaps that have a single sensible answer, so validation sees the values the database will.
     */
    protected function prepareForValidation(): void
    {
        $config = $this->input('config');

        if (! is_array($config)) {
            return;
        }

        // A blank S3 region means AWS's `us-east-1`; a blank endpoint stays '' (the driver reads it as AWS).
        if (StorageProvider::tryFrom((string) $this->input('provider')) === StorageProvider::S3) {
            $config['region'] = ($config['region'] ?? '') === '' ? 'us-east-1' : $config['region'];
            $config['endpoint'] ??= '';
        }

        $this->merge(['config' => $config]);
    }

    /**
     * The destination already holding exactly this credential, if any.
     * Asked after prepareForValidation, so a blank S3 region compares as `us-east-1`.
     */
    public function existingRecord(): ?StorageDestination
    {
        if (! $this->duplicateChecked) {
            $this->duplicateDestination = app(DuplicateIntegration::class)->storageDestination($this->all());
            $this->duplicateChecked = true;
        }

        return $this->duplicateDestination;
    }

    private bool $duplicateChecked = false;

    private ?StorageDestination $duplicateDestination = null;
}
