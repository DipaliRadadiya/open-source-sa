<?php

namespace App\Http\Requests\Server\StorageDestination;

use App\Enums\StorageProvider;
use App\Rules\SingleLine;
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
     * Provider-independent rules, plus whichever `config.*` rules the chosen
     * provider's driver asks for.
     *
     * The driver supplies its rules rather than applying them: validation
     * still lives here, in the FormRequest, which is the only layer that
     * knows this is a create and therefore that credentials are required.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'max:100', Rule::unique('storage_destinations', 'name'), new SingleLine],

            // Required with no default. A default provider would be a silent
            // 's3' on a request that forgot to say — which is exactly the
            // guessing this column was added to end.
            'provider' => ['required', Rule::enum(StorageProvider::class)],

            // Provider-independent: every destination lands somewhere inside
            // its own space, whether that space is an S3 key prefix or a
            // subdirectory of an FTP account.
            'prefix' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]*$#'],
        ], $this->providerRules());
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerRules(): array
    {
        $provider = StorageProvider::tryFrom((string) $this->input('provider'));

        // An unknown provider fails the enum rule above; returning nothing
        // here avoids a second, more confusing error about a config key
        // belonging to a provider that does not exist.
        if ($provider === null) {
            return [];
        }

        return app(StorageDriverFactory::class)->forProvider($provider)->rules(requireSecrets: true);
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator): void {
            $this->validateSftpHasOneAuthMethod($validator);
            $this->validateSftpPrivateKey($validator);
        });
    }

    /**
     * SFTP authenticates with a password *or* a private key, so neither can be
     * `required` on its own — but a destination with neither cannot connect at
     * all, and storing one means the failure arrives at the first backup
     * instead of at the form that could have prevented it.
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

    /**
     * The frontend sends a slim payload; the gaps that have a single sensible
     * answer are filled here rather than in the driver, so validation sees the
     * same values the database will.
     */
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

    protected function prepareForValidation(): void
    {
        $config = $this->input('config');

        if (! is_array($config)) {
            return;
        }

        // Region is S3's only field with a universal default — an empty one
        // means AWS's own, which is `us-east-1`. Endpoint keeps its
        // empty-string sentinel: blank *means* AWS, and the driver reads it
        // that way.
        if (StorageProvider::tryFrom((string) $this->input('provider')) === StorageProvider::S3) {
            $config['region'] = ($config['region'] ?? '') === '' ? 'us-east-1' : $config['region'];
            $config['endpoint'] ??= '';
        }

        $this->merge(['config' => $config]);
    }
}
