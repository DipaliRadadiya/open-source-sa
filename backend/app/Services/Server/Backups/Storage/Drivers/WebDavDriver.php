<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Rules\SafeProviderHost;
use App\Rules\SingleLine;
use Throwable;

/**
 * Any WebDAV server: Nextcloud, ownCloud, Synology, a plain Apache `mod_dav`
 * — and pCloud, which offers no other protocol.
 *
 * ## Why this is "WebDAV" and not "pCloud"
 *
 * The ask was pCloud. pCloud supports neither FTP nor SFTP and has no Flysystem
 * adapter, so WebDAV is the only door — and building a pCloud-shaped feature on
 * it would stake a backup destination on one vendor's weakest surface.
 * Generalising costs nothing and buys the self-hosted servers that this panel's
 * users are far more likely to own.
 *
 * ⚠️ **pCloud's own documentation says WebDAV is for "small files" and that its
 * stability "may have interruptions"**, recommending their desktop app for
 * large transfers. A site archive is not a small file. That is the vendor
 * describing their own service as unsuitable for what this feature does, so
 * pCloud is offered as a preset with that warning attached rather than as a
 * headline destination — see the `pcloud_warning` string.
 *
 * ⚠️ **2FA breaks it.** Reported February 2026: with two-factor enabled on a
 * pCloud account the WebDAV endpoint resets the TCP connection rather than
 * answering with a 401, so there is no authentication error to report — just a
 * timeout. The panel classifies a reset as unreachable, which is honest but
 * unhelpful, so the copy names 2FA as a thing to check.
 */
class WebDavDriver implements StorageDriver
{
    use ClassifiesFailures;

    public function provider(): StorageProvider
    {
        return StorageProvider::WebDav;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        return [
            'driver' => 'webdav',
            'baseUri' => rtrim((string) $destination->configValue('base_uri', ''), '/').'/',
            'userName' => $destination->configValue('username'),
            'password' => $destination->configValue('password'),
            // The destination's folder beneath the base URI. WebDAV is a real
            // directory tree, so this nests rather than prefixing a key.
            'prefix' => trim((string) $destination->prefix, '/'),

            // Same reason as every other driver: without it a failed write
            // returns false, and a backup that never happened is
            // indistinguishable from one that did.
            'throw' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(bool $requireSecrets = true): array
    {
        $secret = $requireSecrets ? ['required'] : ['sometimes', 'required'];

        return [
            // A full https URL, and the same SSRF guard the S3 endpoint uses —
            // this is an operator-supplied address the panel then connects to
            // from inside the network. `RemoteHost` refuses loopback and the
            // metadata range however they are spelled.
            'config.base_uri' => ['required', 'string', 'max:255', new SafeProviderHost(
                'storage.test.invalid_endpoint',
                'storage.test.forbidden_host',
            )],
            'config.username' => ['required', 'string', 'max:255', new SingleLine],
            'config.password' => [...$secret, 'string', 'max:512'],
        ];
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
            'base_uri' => $destination->configValue('base_uri'),
            'username' => $destination->configValue('username'),
        ];
    }

    /**
     * Nothing to check up front — unlike Drive, a successful write here really
     * does mean the destination works.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        return null;
    }

    protected function categoryForType(Throwable $e): ?string
    {
        return null;
    }

    /**
     * WebDAV answers in HTTP, so the status code is the evidence. Sabre wraps
     * them as exceptions whose messages carry the code.
     */
    protected function categoryForMessage(string $message): ?string
    {
        if (str_contains($message, '401')
            || str_contains($message, '403')
            || str_contains($message, 'unauthorized')
            || str_contains($message, 'authentication')) {
            return 'storage.test.invalid_credentials';
        }

        // The base URI resolves but the folder beneath it does not exist —
        // the most likely first-run mistake, and completely different from an
        // unreachable server.
        if (str_contains($message, '404') || str_contains($message, 'not found')) {
            return 'storage.test.root_missing';
        }

        // 507 is WebDAV's own "insufficient storage". Nextcloud sends it when
        // a quota is full, and reporting that as "unreachable" would send
        // someone to check a network that is working perfectly.
        if (str_contains($message, '507') || str_contains($message, 'insufficient storage')) {
            return 'storage.test.dav_full';
        }

        // A connection reset with no HTTP response at all is what pCloud does
        // when the account has 2FA enabled — there is no 401 to read, so this
        // is as specific as the evidence allows.
        if (str_contains($message, 'reset') || str_contains($message, 'timed out')) {
            return 'storage.test.dav_reset';
        }

        return null;
    }

    /**
     * Nothing to repair: this destination's location is not something the panel
     * created, so its absence is somebody else's decision to undo.
     */
    public function heal(StorageDestination $destination): void {}
}
