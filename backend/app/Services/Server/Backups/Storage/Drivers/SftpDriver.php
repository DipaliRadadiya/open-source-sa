<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use League\Flysystem\PhpseclibV3\UnableToLoadPrivateKey;
use Throwable;

/**
 * SFTP over SSH. Password or private key.
 *
 * **Host-key verification is the point.** An SFTP destination that accepts
 * whatever key the far end presents is encrypted against a passive observer
 * and wide open to anyone who can answer first — which, for a backup channel
 * carrying a site's database and `.env`, is the whole threat. So the
 * fingerprint is recorded the first time the panel connects and enforced
 * afterwards (trust on first use); a changed key stops the transfer and says
 * so, rather than uploading the archive to whoever is now answering.
 */
class SftpDriver extends RemoteHostDriver
{
    use ClassifiesFailures;

    private const DEFAULT_PORT = 22;

    public function provider(): StorageProvider
    {
        return StorageProvider::Sftp;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        $privateKey = $destination->configValue('private_key');

        return [
            'driver' => 'sftp',
            'host' => $destination->configValue('host'),
            'username' => $destination->configValue('username'),
            'port' => (int) ($destination->configValue('port') ?: self::DEFAULT_PORT),
            'root' => $this->remoteRoot($destination),

            // Key and password are alternatives, and passing an empty string
            // for the unused one is not the same as omitting it: phpseclib
            // will try to authenticate with it and fail. Only the one that is
            // actually set is handed over.
            ...($privateKey
                ? array_filter([
                    'privateKey' => $privateKey,
                    'passphrase' => $destination->configValue('passphrase') ?: null,
                ], fn ($v) => $v !== null)
                : ['password' => $destination->configValue('password')]),

            // Pinned on every connection after the first. Null means the
            // panel has not met this host yet — see `StorageConnectionProber`,
            // which records what it saw.
            ...array_filter([
                'hostFingerprint' => $destination->configValue('host_fingerprint') ?: null,
            ], fn ($v) => $v !== null),

            'timeout' => 30,

            // One attempt, not flysystem's default five. Its retry loop also
            // retries a *refused login*, so one Test with a mistyped password
            // was five failed logins on the user's server — which is
            // fail2ban's default `maxretry`, i.e. the backup server banning
            // this panel. And OpenSSH 10 drops the retries from an address
            // that just failed ("penalty: failed authentication"), so the last
            // error was "could not connect" and a wrong key was reported as
            // `unreachable`. Measured on a real server, 2026-09-23.
            'maxTries' => 0,

            // Same reason as every other driver: a swallowed failure is a
            // backup that reports success over an empty directory.
            'throw' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(bool $requireSecrets = true): array
    {
        return array_merge($this->hostRules(self::DEFAULT_PORT), [
            // Exactly one of the two is needed, and which one is the user's
            // choice — so neither is unconditionally required. The
            // "at least one" check is the FormRequest's, because it is the
            // only layer that can see whether the *stored* destination
            // already has one during a partial update.
            'config.password' => ['nullable', 'string', 'max:512'],
            'config.private_key' => ['nullable', 'string', 'max:16384'],
            'config.passphrase' => ['nullable', 'string', 'max:512'],
        ]);
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return ['password', 'private_key', 'passphrase'];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(StorageDestination $destination): array
    {
        return [
            'host' => $destination->configValue('host'),
            'port' => (int) ($destination->configValue('port') ?: self::DEFAULT_PORT),
            'username' => $destination->configValue('username'),
            'root' => $destination->configValue('root'),
            // Which auth method is in use, never the material itself. The UI
            // needs this to label the rotate dialog correctly — rotating a
            // key is not rotating a password.
            'auth_method' => $destination->configValue('private_key') ? 'private_key' : 'password',
            // Safe to show: a public key fingerprint is public by definition,
            // and displaying it is how an operator verifies the box is the
            // one they think it is.
            'host_fingerprint' => $destination->configValue('host_fingerprint'),
        ];
    }

    /**
     * A changed host key gets its own category rather than being folded into
     * "unreachable". The host is reachable — that is precisely the problem,
     * and "could not connect" would send the operator to check the firewall
     * while a possible interception went unmentioned.
     *
     * An unloadable private key is the user's paste, not the server: telling
     * them the destination is unreachable would be a lie about whose fault it
     * is.
     */
    protected function categoryForType(Throwable $e): ?string
    {
        if ($e instanceof UnableToEstablishAuthenticityOfHost) {
            return 'storage.test.host_key_mismatch';
        }

        if ($e instanceof UnableToLoadPrivateKey) {
            return 'storage.test.invalid_private_key';
        }

        if ($e instanceof UnableToAuthenticate) {
            return 'storage.test.invalid_credentials';
        }

        if ($e instanceof UnableToConnectToSftpHost) {
            return 'storage.test.unreachable';
        }

        return null;
    }

    protected function categoryForMessage(string $message): ?string
    {
        // Deliberately not 'authenticity' — that is the host-key failure, and
        // it is answered by type above. Matching it here would relabel a
        // possible interception as a bad password.
        if (str_contains($message, 'authentication')
            || str_contains($message, 'permission denied')
            || str_contains($message, 'login')) {
            return 'storage.test.invalid_credentials';
        }

        return null;
    }
}
