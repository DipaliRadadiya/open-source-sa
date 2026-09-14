<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use League\Flysystem\Ftp\UnableToAuthenticate;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use League\Flysystem\Ftp\UnableToResolveConnectionRoot;
use Throwable;

/**
 * FTP, optionally over TLS (FTPS).
 *
 * ⚠️ **Plain FTP sends the password and the entire backup archive in the
 * clear.** It is supported because real infrastructure still runs it and
 * refusing outright would push people to keep no off-box backup at all — but
 * `ssl` defaults to on, and a destination that turns it off is labelled as
 * insecure everywhere it appears. The choice is the operator's; pretending it
 * is a safe one is not.
 */
class FtpDriver extends RemoteHostDriver
{
    use ClassifiesFailures;

    private const DEFAULT_PORT = 21;

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

            // Defaults chosen for the case that actually happens. TLS on,
            // because the alternative ships credentials in cleartext. Passive
            // on, because the panel is usually behind NAT and an active-mode
            // transfer needs the *server* to open a connection back to it,
            // which a firewall will drop.
            'ssl' => (bool) ($destination->configValue('ssl') ?? true),
            'passive' => (bool) ($destination->configValue('passive') ?? true),

            // A backup upload is long; a stalled control connection should
            // not hang a queue worker indefinitely.
            'timeout' => 30,

            // Same reason as S3: without it a failed write returns false and
            // an upload that never happened looks exactly like one that did.
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
        ];
    }

    /**
     * FTP separates the two cases cleanly, which S3 does not: the adapter
     * raises `UnableToAuthenticate` for a rejected login and
     * `UnableToConnectToFtpHost` for everything about getting there.
     *
     * The `530` fallback covers the case where the failure surfaces as a raw
     * server reply rather than a typed exception — 530 is FTP's "not logged
     * in", and without it a wrong password reads as an unreachable host and
     * sends the user to check DNS.
     */
    protected function categoryForType(Throwable $e): ?string
    {
        if ($e instanceof UnableToAuthenticate) {
            return 'storage.test.invalid_credentials';
        }

        // The host answered and the login succeeded; the folder is not there.
        // Measured against a live FTP server: this is what a brand-new
        // destination pointed at a folder the user has not created yet does,
        // and it is the single most likely first-run mistake. Reporting it as
        // "unreachable" sends them to check DNS and a firewall over a missing
        // directory. FTP surfaces it where SFTP does not, because the FTP
        // adapter resolves its root by changing into it and refuses when that
        // fails, rather than creating it.
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
}
