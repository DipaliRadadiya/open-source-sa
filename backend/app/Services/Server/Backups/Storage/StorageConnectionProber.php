<?php

namespace App\Services\Server\Backups\Storage;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes a storage destination by writing a random key, reading it back and
 * deleting it. The disk is built on demand and never registered globally, so
 * the runtime credentials never leak into the framework's shared
 * `filesystems.disks` map and bleed across queue workers, Octane tasks, or any
 * other code that resolves a named disk.
 *
 * Failure categories are translated into user-friendly messages; raw exception
 * text (which can carry URLs, bucket names, hostnames, partial access keys) is
 * held inside the result for the operator log only — never surfaced to the
 * response body.
 *
 * **Categorisation is the driver's job, not this class's.** It used to live
 * here and fingerprinted AWS SDK exception names, which was correct while S3
 * was the only provider and quietly wrong the moment another existed: an SFTP
 * authentication failure matches none of those strings and was reported as
 * "unreachable", sending someone to check their hostname when their password
 * was what was wrong.
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
        // Wrap callable into a Closure so the typed property is satisfied
        // whether or not the caller passed one.
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
        // Flat, not `.probe/<uuid>.bin`. A key with a directory in it makes the
        // provider create that directory, and deleting the object afterwards
        // does not remove it — so every Test connection left a `.probe` folder
        // behind for good. On S3 a prefix is not a real object and nobody
        // noticed; on Google Drive, FTP and SFTP it is a real
        // directory, and on Drive it sits in somebody's *personal* account
        // beside their photos, where a dot does not even make it hidden.
        //
        // One object, created and removed. Nothing to clean up because nothing
        // else is made.
        $key = '.probe-'.Str::uuid()->toString().'.bin';

        $driver = $this->drivers->for($destination);

        // Learn the host key *before* connecting, not after, so the probe
        // itself runs pinned. Capturing it afterwards would mean the probe
        // trusted one host and the pin recorded another — two trust decisions
        // where there should be one.
        $this->rememberHostKeyOnFirstUse($destination);

        // A precondition the round trip cannot answer. Google Drive is the
        // reason: a service account has no storage quota, so a small sentinel
        // can be accepted into a folder that will refuse a real archive — the
        // probe would go green and the first backup would fail at 3am. Asked
        // before the write rather than after, so a destination that cannot
        // work is never reported as working.
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

            $disk->put($key, $payload);
            $read = $disk->get($key);
            $disk->delete($key);

            // Sweep up the folder older versions left behind. Best-effort and
            // swallowed: it is litter, not a credential check, and a provider
            // that refuses must not turn a working destination's test red.
            // Only ever held sentinels, so there is nothing of anyone's in it.
            try {
                $disk->deleteDirectory('.probe');
            } catch (Throwable) {
                // Nothing to report — the probe's verdict is about the
                // round-trip above, not about tidying.
            }

            // A round-trip with a wrong read can mean a CDN cache, a
            // transparent proxy, or a real corruption — all of which
            // present as "succeeded, but you'll never get your backups
            // back". Treat it as a failure.
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
                'message' => __('storage.test.success'),
                'error_class' => null,
                'detail' => null,
            ];
        } catch (Throwable $e) {
            return $this->failure(
                destination: $destination,
                durationMs: $this->elapsed($start),
                i18nKey: $driver->classify($e),
                exception: $e,
            );
        }
    }

    /**
     * Record an SFTP host's fingerprint the first time the panel meets it.
     *
     * Only ever *writes* — it never replaces a stored fingerprint. That is the
     * whole security property: if this overwrote on mismatch, the pin would
     * re-pin itself to the impostor and the check would be decorative.
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
            // A public host key fingerprint is public by definition — this is
            // the one piece of connection detail that is safe to log, and
            // having it in the log is how a later mismatch gets investigated.
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
        // Identify the destination by id/name/provider only. The credentials
        // are the one thing that must never reach a log file, and the raw
        // SDK message can carry a partial access key — so it is logged as
        // its own field a redactor can target, not folded into the summary.
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
            // Stable machine-readable category. Derived from the i18n key
            // rather than mapped by hand, so a driver adding a category
            // cannot forget to register it here and silently report the
            // wrong one.
            'error_class' => Str::after($i18nKey, 'storage.test.'),
            // Raw error string. Never echoed in the API body — the client
            // only ever sees `error_class` and the translated `message`. It
            // is logged so a support ticket has something concrete behind the
            // generic user-facing wording.
            'detail' => $exception?->getMessage(),
        ];
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
