<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Enums\StorageProvider;
use App\Exceptions\Server\Storage\FtpsCertificateException;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\FtpsCertificate;
use League\Flysystem\Ftp\UnableToAuthenticate;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use League\Flysystem\Ftp\UnableToResolveConnectionRoot;
use Throwable;

/**
 * FTP, optionally over TLS (FTPS).
 *
 * ⚠️ Plain FTP sends the password and the whole backup archive in the clear. It stays supported
 * because refusing it would leave some users with no off-box backup, but `ssl` defaults to on and
 * a destination with it off is labelled insecure everywhere it appears.
 */
class FtpDriver extends RemoteHostDriver
{
    use ClassifiesFailures;

    private const DEFAULT_PORT = 21;

    /** Bounds a stalled socket, not the transfer: PHP applies it per network operation. */
    private const TRANSFER_TIMEOUT_SECONDS = 30;

    public function provider(): StorageProvider
    {
        return StorageProvider::Ftp;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        return [
            'driver' => 'ftp',
            'host' => $destination->configValue('host'),
            'username' => $destination->configValue('username'),
            'password' => $destination->configValue('password'),
            'port' => (int) ($destination->configValue('port') ?: self::DEFAULT_PORT),
            'root' => $this->remoteRoot($destination),

            // TLS on: otherwise credentials go in cleartext. Passive on: the panel is usually
            // behind NAT, and active mode needs the server to connect back, which a firewall drops.
            'ssl' => (bool) ($destination->configValue('ssl') ?? true),
            'passive' => (bool) ($destination->configValue('passive') ?? true),

            // A stalled control connection must not hang a queue worker.
            'timeout' => self::TRANSFER_TIMEOUT_SECONDS,

            // Without it a failed write returns false and looks like a successful upload.
            'throw' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(bool $requireSecrets = true): array
    {
        $secret = $requireSecrets ? ['required'] : ['sometimes', 'required'];

        return array_merge($this->hostRules(self::DEFAULT_PORT), [
            'config.password' => [...$secret, 'string', 'max:512'],
            'config.ssl' => ['nullable', 'boolean'],
            'config.passive' => ['nullable', 'boolean'],
            // After a legitimate certificate renewal: forget the pinned fingerprint so the
            // next connection records the new one.
            'config.trust_new_certificate' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return ['password'];
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
            'ssl' => (bool) ($destination->configValue('ssl') ?? true),
            'passive' => (bool) ($destination->configValue('passive') ?? true),
            // The pinned certificate; a fingerprint is public, so safe to show.
            'tls_fingerprint' => $destination->configValue('tls_fingerprint'),
        ];
    }

    /**
     * Checked before every backup, restore or test connection (see DestinationDisk::for).
     *
     * @throws FtpsCertificateException
     */
    public function heal(StorageDestination $destination): void
    {
        $this->verifyCertificate($destination);
    }

    public function preflight(StorageDestination $destination): ?string
    {
        if (($blocked = parent::preflight($destination)) !== null) {
            return $blocked;
        }

        try {
            $this->verifyCertificate($destination);
        } catch (FtpsCertificateException $e) {
            return $e->i18nKey;
        }

        return null;
    }

    /**
     * Pin the FTPS certificate on first use and refuse a different one after (trust on first use,
     * like SFTP host keys). Plain FTP has nothing to check.
     *
     * @throws FtpsCertificateException
     */
    public function verifyCertificate(StorageDestination $destination): void
    {
        if (! (bool) ($destination->configValue('ssl') ?? true)) {
            return;
        }

        $pinned = (string) ($destination->configValue('tls_fingerprint') ?? '');
        $current = app(FtpsCertificate::class)->fingerprint(
            (string) $destination->configValue('host', ''),
            (int) ($destination->configValue('port') ?: self::DEFAULT_PORT),
        );

        if ($pinned === '') {
            // First use. If nothing is readable, the real connection fails with its own reason.
            if ($current !== null) {
                $destination->mergeConfig(['tls_fingerprint' => $current]);

                if ($destination->exists) {
                    $destination->save();
                }
            }

            return;
        }

        if ($current === null) {
            throw new FtpsCertificateException('storage.test.tls_certificate_unreadable');
        }

        if (! hash_equals($pinned, $current)) {
            throw new FtpsCertificateException('storage.test.tls_certificate_changed');
        }
    }

    /**
     * The adapter raises `UnableToAuthenticate` for a rejected login and `UnableToConnectToFtpHost`
     * for everything about getting there. The `530` ("not logged in") fallback in
     * `categoryForMessage()` covers failures that surface as a raw server reply.
     */
    protected function categoryForType(Throwable $e): ?string
    {
        if ($e instanceof FtpsCertificateException) {
            return $e->i18nKey;
        }

        if ($e instanceof UnableToAuthenticate) {
            return 'storage.test.invalid_credentials';
        }

        // Login worked but the folder is missing (likely first-run mistake). The FTP adapter
        // resolves its root by changing into it and refuses instead of creating it.
        if ($e instanceof UnableToResolveConnectionRoot) {
            return 'storage.test.root_missing';
        }

        if ($e instanceof UnableToConnectToFtpHost) {
            return 'storage.test.unreachable';
        }

        return null;
    }

    protected function categoryForMessage(string $message): ?string
    {
        if (str_contains($message, '530')
            || str_contains($message, 'not logged in')
            || str_contains($message, 'login incorrect')
            || str_contains($message, 'authentication failed')) {
            return 'storage.test.invalid_credentials';
        }

        return null;
    }

    /**
     * Does this server offer encryption (FTPS) at all?
     *
     * Asked only after an encrypted login was refused: a server without TLS refuses `AUTH TLS`
     * with 530 (vsftpd), the same code as a wrong password. `234` is the only answer meaning yes.
     * Null when the server could not be asked, so the caller keeps its own verdict.
     */
    public function supportsTls(StorageDestination $destination): ?bool
    {
        $connection = @ftp_connect(
            (string) $destination->configValue('host', ''),
            (int) ($destination->configValue('port') ?: self::DEFAULT_PORT),
            10,
        );

        if ($connection === false) {
            return null;
        }

        try {
            $reply = (string) (@ftp_raw($connection, 'AUTH TLS')[0] ?? '');
        } finally {
            @ftp_close($connection);
        }

        return $reply === '' ? null : str_starts_with($reply, '234');
    }

    /**
     * Download straight onto disk, never through `php://temp`.
     *
     * The adapter's `readStream()` buffers the whole object in `php://temp`, which spills into the
     * system temp directory (a small tmpfs), so large restores fail. `ftp_fget` writes to the
     * destination file directly instead.
     *
     * Returns false rather than throwing when it cannot connect: the caller falls back to the
     * streaming copy.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool
    {
        $host = (string) $destination->configValue('host', '');
        $port = (int) ($destination->configValue('port') ?: self::DEFAULT_PORT);
        $ssl = (bool) ($destination->configValue('ssl') ?? true);
        $timeout = self::TRANSFER_TIMEOUT_SECONDS;

        // Opens its own connection (not via the disk), so it checks the certificate itself.
        $this->verifyCertificate($destination);

        $connection = $ssl
            ? @ftp_ssl_connect($host, $port, $timeout)
            : @ftp_connect($host, $port, $timeout);

        if ($connection === false) {
            return false;
        }

        try {
            $user = (string) $destination->configValue('username', '');
            $password = (string) $destination->configValue('password', '');

            if (! @ftp_login($connection, $user, $password)) {
                return false;
            }

            // Passive unless turned off, for the reason given in `config()`.
            @ftp_pasv($connection, (bool) ($destination->configValue('passive') ?? true));
            @ftp_set_option($connection, FTP_TIMEOUT_SEC, $timeout);

            $root = $this->remoteRoot($destination);
            $remote = ($root === '' ? '' : rtrim($root, '/').'/').ltrim($key, '/');

            $handle = fopen($path, 'wb');

            if ($handle === false) {
                return false;
            }

            try {
                return @ftp_fget($connection, $handle, $remote, FTP_BINARY);
            } finally {
                fclose($handle);
            }
        } finally {
            @ftp_close($connection);
        }
    }
}
