<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use League\Flysystem\PhpseclibV3\UnableToLoadPrivateKey;
use RuntimeException;
use Throwable;

/**
 * SFTP over SSH. Password or private key.
 *
 * **Host-key verification is the point.** A destination that accepts any key is open to whoever
 * answers first, and the backup carries a site's database and `.env`. So the fingerprint is
 * recorded on the first connection and enforced afterwards (trust on first use); a changed key
 * stops the transfer instead of uploading the archive to whoever is now answering.
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

            // Key and password are alternatives: an empty string for the unused one is not the same
            // as omitting it (phpseclib tries it and fails). Pass only the one that is set.
            ...($privateKey
                ? array_filter([
                    'privateKey' => $privateKey,
                    'passphrase' => $destination->configValue('passphrase') ?: null,
                ], fn ($v) => $v !== null)
                : ['password' => $destination->configValue('password')]),

            // Pinned on every connection after the first; null means the host is new
            // (`StorageConnectionProber` records it).
            ...array_filter([
                'hostFingerprint' => $destination->configValue('host_fingerprint') ?: null,
            ], fn ($v) => $v !== null),

            'timeout' => 30,

            // One attempt, not flysystem's default five: its retry loop also retries a refused
            // login, so one Test with a wrong password becomes five failed logins (fail2ban's
            // default `maxretry`), and OpenSSH 10 then drops the retries, which hides the real error.
            'maxTries' => 0,

            // A swallowed failure would report a successful backup over an empty directory.
            'throw' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(bool $requireSecrets = true): array
    {
        return array_merge($this->hostRules(self::DEFAULT_PORT), [
            // One of the two is needed, so neither is required here. The "at least one" check is the
            // FormRequest's: only it can see whether the stored destination already has one.
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
            // The auth method in use, never the secret itself; the UI labels the rotate dialog with it.
            'auth_method' => $destination->configValue('private_key') ? 'private_key' : 'password',
            // Safe to show: a host key fingerprint is public, and lets the user verify the box.
            'host_fingerprint' => $destination->configValue('host_fingerprint'),
        ];
    }

    /**
     * Download straight onto disk, never through `php://temp`.
     *
     * The adapter's `readStream()` buffers the whole archive in `php://temp` (a small tmpfs), the
     * same trap as {@see FtpDriver::downloadTo()}. phpseclib's `get()` writes to a local path chunk
     * by chunk, so it is handed the destination file instead.
     *
     * Same credentials, pinned host key and single login attempt as an upload. A refused connection
     * or a failed transfer throws rather than returning false: the caller's fallback would log in a
     * second time, and the typed exception is what `classify()` names.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool
    {
        $config = $this->config($destination);
        $connection = SftpConnectionProvider::fromArray($config)->provideConnection();

        try {
            $root = (string) $config['root'];
            $remote = ($root === '' ? '' : rtrim($root, '/').'/').ltrim($key, '/');

            if ($connection->get($remote, $path) === false) {
                throw new RuntimeException('the archive could not be downloaded from the SFTP server');
            }

            return true;
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * A changed host key gets its own category, not "unreachable": the host is reachable, and
     * "could not connect" would hide a possible interception. An unloadable private key is the
     * user's paste, not a server fault.
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
        // Not 'authenticity': that is the host-key failure, handled by type above. Matching it here
        // would report a possible interception as a bad password.
        if (str_contains($message, 'authentication')
            || str_contains($message, 'permission denied')
            || str_contains($message, 'login')) {
            return 'storage.test.invalid_credentials';
        }

        return null;
    }
}
