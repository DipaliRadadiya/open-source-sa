<?php

namespace App\Services\Server\Backups\Storage;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\Drivers\FtpDriver;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes a storage destination by writing a random key, reading it back and deleting it. The
 * disk is built on demand and never registered globally, so runtime credentials never leak
 * into the shared `filesystems.disks` map.
 *
 * Failure categories become user-friendly messages; raw exception text (which can carry URLs,
 * bucket names, hostnames, partial access keys) stays in the result for the operator log only,
 * never in the response body.
 *
 * **Categorisation is the driver's job, not this class's:** provider-specific exception
 * fingerprints cannot be shared (an SFTP auth failure matches no AWS SDK string).
 */
class StorageConnectionProber
{
    /** @var Closure(array<string, mixed>): Filesystem */
    private Closure $diskBuilder;

    /**
     * @param  null|callable(array<string, mixed>): Filesystem  $diskBuilder
     *                                                                        Defaults to `Storage::build($config)` so production takes
     *                                                                        the canonical ephemeral-disk path. Tests inject a fake.
     */
    public function __construct(
        private StorageDriverFactory $drivers,
        private SftpHostKey $hostKeys,
        ?callable $diskBuilder = null,
    ) {
        $this->diskBuilder = $diskBuilder !== null
            ? Closure::fromCallable($diskBuilder)
            : static fn (array $config): Filesystem => Storage::build($config);
    }

    /**
     * @return array{
     *     success: bool,
     *     latency_ms: int,
     *     message: string,
     *     error_class: string|null,
     *     detail: string|null
     * }
     */
    public function probe(StorageDestination $destination): array
    {
        $start = microtime(true);
        $payload = Str::random(64);
        // Flat, not `.probe/<uuid>.bin`: a key with a directory makes Drive, FTP and SFTP create a
        // real directory that deleting the object does not remove.
        $key = '.probe-'.Str::uuid()->toString().'.bin';

        $driver = $this->drivers->for($destination);

        // Learn the host key *before* connecting, so the probe itself runs pinned (one trust
        // decision, not two).
        $this->rememberHostKeyOnFirstUse($destination);

        // A precondition the round trip cannot answer: a Google Drive service account has no
        // quota, so a small sentinel can succeed where a real archive would be refused.
        $precondition = $driver->preflight($destination);

        if ($precondition !== null) {
            return $this->failure(
                destination: $destination,
                durationMs: $this->elapsed($start),
                i18nKey: $precondition,
                exception: null,
            );
        }

        try {
            $disk = ($this->diskBuilder)($driver->config($destination));

            // Create the destination's own folder first, as a real backup does: SFTP creates
            // parents only, so a missing prefix would fail the test as `unreachable` while
            // backups worked. No-op when it exists. FTP cannot (it enters its root to connect;
            // a missing one is `root_missing`).
            if ($destination->provider === StorageProvider::Sftp) {
                $disk->makeDirectory('');
            }

            $disk->put($key, $payload);
            $read = $disk->get($key);
            $disk->delete($key);

            // Sweep up the `.probe` folder older versions left behind (it held only sentinels).
            // Best effort: a provider that refuses must not turn a working destination red.
            try {
                $disk->deleteDirectory('.probe');
            } catch (Throwable) {
                // The verdict is about the round trip above, not tidying.
            }

            // A wrong read (CDN cache, transparent proxy, corruption) means backups could not
            // be recovered: a failure.
            if (! is_string($read) || ! hash_equals($payload, $read)) {
                return $this->failure(
                    destination: $destination,
                    durationMs: $this->elapsed($start),
                    i18nKey: 'storage.test.mismatch',
                    exception: null,
                );
            }

            return [
                'success' => true,
                'latency_ms' => $this->elapsed($start),
                'message' => $this->successMessage($destination),
                'error_class' => null,
                'detail' => null,
            ];
        } catch (Throwable $e) {
            $key = $driver->classify($e);

            // An encrypted FTP login refused by a server without TLS reads as a wrong password:
            // ask the server which.
            if ($key === 'storage.test.invalid_credentials'
                && $driver instanceof FtpDriver
                && (bool) ($destination->configValue('ssl') ?? true)
                && $driver->supportsTls($destination) === false) {
                $key = 'storage.test.ftp_no_tls';
            }

            return $this->failure(
                destination: $destination,
                durationMs: $this->elapsed($start),
                i18nKey: $key,
                exception: $e,
            );
        }
    }

    /**
     * Record an SFTP host's fingerprint the first time the panel meets it.
     *
     * Only ever *writes*, never replaces a stored fingerprint: overwriting on mismatch would
     * re-pin to an impostor.
     */
    private function rememberHostKeyOnFirstUse(StorageDestination $destination): void
    {
        if ($destination->provider !== StorageProvider::Sftp) {
            return;
        }

        if (filled($destination->configValue('host_fingerprint'))) {
            return;
        }

        $host = (string) $destination->configValue('host', '');

        if ($host === '') {
            return;
        }

        $fingerprint = $this->hostKeys->fingerprint(
            $host,
            (int) ($destination->configValue('port') ?: 22),
        );

        if ($fingerprint === null) {
            return;
        }

        $destination->mergeConfig(['host_fingerprint' => $fingerprint]);
        $destination->save();

        Log::info('Recorded SFTP host fingerprint on first use.', [
            'feature' => 'storage',
            'destination_id' => $destination->getKey(),
            // A host key fingerprint is public: the one connection detail safe to log.
            'host_fingerprint' => $fingerprint,
        ]);
    }

    /**
     * @return array{
     *     success: bool,
     *     latency_ms: int,
     *     message: string,
     *     error_class: string|null,
     *     detail: string|null
     * }
     */
    private function failure(
        StorageDestination $destination,
        int $durationMs,
        string $i18nKey,
        ?Throwable $exception,
    ): array {
        // Identify the destination by id/name/provider only: credentials must never reach a log,
        // and the raw SDK message can carry a partial access key, so it gets its own field a
        // redactor can target.
        Log::warning('Storage destination probe failed.', [
            'feature' => 'storage',
            'destination_id' => $destination->getKey(),
            'destination_name' => $destination->name,
            'provider' => $destination->provider->value,
            'error_class' => $i18nKey,
            'latency_ms' => $durationMs,
            'detail' => $exception?->getMessage(),
        ]);

        return [
            'success' => false,
            'latency_ms' => $durationMs,
            'message' => __($i18nKey),
            // Stable machine-readable category, derived from the i18n key so a driver's new
            // category cannot be left unregistered here.
            'error_class' => Str::after($i18nKey, 'storage.test.'),
            // Raw error string. Never echoed in the API body; logged for support.
            'detail' => $exception?->getMessage(),
        ];
    }

    /**
     * "Connection succeeded", except for a folder on this server, where the useful news is how
     * much room is left on its disk.
     */
    private function successMessage(StorageDestination $destination): string
    {
        if ($destination->provider === StorageProvider::Local) {
            ['free' => $free, 'total' => $total] = app(LocalBackupFolder::class)->space((string) $destination->configValue('path'));

            if ($free !== null && $total !== null) {
                return __('storage.test.local_success', ['free' => Number::fileSize($free, 1), 'total' => Number::fileSize($total, 1)]);
            }
        }

        return __('storage.test.success');
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
