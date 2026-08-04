<?php

namespace App\Services\Server\Backups\Storage;

use App\Models\StorageDestination;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes an S3-compatible storage destination by writing a random key,
 * reading it back and deleting it. The disk is built on demand and never
 * registered globally, so the runtime credentials never leak into the
 * framework's shared `filesystems.disks` map and bleed across queue
 * workers, Octane tasks, or any other code that resolves a named disk.
 *
 * Failure categories are translated into user-friendly messages; raw
 * exception text (which can carry S3 URLs, bucket names, partial access
 * keys) is held inside the result for the operator log only — never
 * surfaced to the response body.
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
    public function __construct(?callable $diskBuilder = null)
    {
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
        $key = '.probe/'.Str::uuid()->toString().'.bin';

        try {
            $disk = ($this->diskBuilder)($this->driverConfig($destination));

            $disk->put($key, $payload);
            $read = $disk->get($key);
            $disk->delete($key);

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
                i18nKey: $this->classify($e),
                exception: $e,
            );
        }
    }

    /**
     * Map the destination's stored columns to the S3 driver config the
     * FilesystemManager expects. `use_path_style_endpoint` is enabled
     * by default — MinIO, Wasabi, Backblaze B2 and other S3-compatible
     * providers route everything through the path rather than virtual
     * hostnames, and modern AWS endpoints accept it too.
     *
     * The plaintext credentials come back from the model's encrypted
     * cast on `$destination->access_key` / `secret_key`. That string
     * never leaves this method — it flows into a closure-local disk
     * instance and is GC'd with it. See the class doc for why the disk
     * itself is built on-demand rather than registered globally.
     *
     * @return array<string, mixed>
     */
    private function driverConfig(StorageDestination $destination): array
    {
        return [
            'driver' => 's3',
            'key' => $destination->access_key,
            'secret' => $destination->secret_key,
            'region' => $destination->region ?: 'us-east-1',
            'bucket' => $destination->bucket,
            'endpoint' => $destination->endpoint ?: null,
            // Hidden behind the `root` so an application uploading via a
            // destination cannot escape into another tenant's prefix.
            'root' => $destination->prefix ?: '',

            // Path-style only when a custom endpoint is set. MinIO, Wasabi
            // and Backblaze B2 route through the path; real AWS S3 does not
            // — path-style is deprecated there and unsupported for buckets
            // in regions launched after 2019. An empty endpoint *means*
            // AWS, so forcing it on would break the default provider.
            'use_path_style_endpoint' => filled($destination->endpoint),

            // MUST stay true. With `throw => false` the Flysystem adapter
            // swallows every failure (FilesystemAdapter::get() returns null
            // instead of raising), so a rejected credential or unreachable
            // bucket would never reach the catch below — it would fall into
            // the read-back comparison and be reported as "wrote and read
            // back different bytes". classify() would become dead code and
            // the user would be told the wrong thing about every failure.
            'throw' => true,
        ];
    }

    /**
     * Categorise an exception into a translated string without leaking
     * its raw text in the resulting `message`. The AWS SDK's exception
     * strings are useful for fingerprinting to an attacker and rarely
     * useful for an end user — the translated `storage.test.unreachable`
     * is the same actionable message in every situation.
     */
    private function classify(Throwable $e): string
    {
        $name = $e::class;
        $message = strtolower($e->getMessage());

        // The S3 SDK tags credential failures with one of these in the
        // class name or message; we map them to a single translated key.
        // The lowercased "invalidaccesskeyid" form covers the substring
        // the SDK uses in both exception names and error message prefixes
        // when only the message is available to us.
        if (str_contains($name, 'InvalidAccessKeyId')
            || str_contains($name, 'SignatureDoesNotMatch')
            || str_contains($name, 'AccessDenied')
            || str_contains($name, 'Aws\S3\Exception')
            || str_contains($message, 'invalidaccesskeyid')
            || str_contains($message, 'invalid access key')
            || str_contains($message, 'signature')
            || str_contains($message, 'access denied')
            || str_contains($message, '403')) {
            return 'storage.test.invalid_credentials';
        }

        // Network/DNS/SSL problems. Bucket-not-found (NoSuchBucket) also
        // arrives here — categorising it would still leave the user with
        // the actionable "could not connect" message they can act on
        // (check bucket name / region).
        return 'storage.test.unreachable';
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
        // Identify the destination by id/name/bucket only. The credentials
        // are the one thing that must never reach a log file, and the raw
        // SDK message can carry a partial access key — so it is logged as
        // its own field a redactor can target, not folded into the summary.
        Log::warning('Storage destination probe failed.', [
            'feature' => 'storage',
            'destination_id' => $destination->getKey(),
            'destination_name' => $destination->name,
            'bucket' => $destination->bucket,
            'error_class' => $i18nKey,
            'latency_ms' => $durationMs,
            'detail' => $exception?->getMessage(),
        ]);

        return [
            'success' => false,
            'latency_ms' => $durationMs,
            'message' => __($i18nKey),
            // Stable machine-readable category: 'invalid_credentials' or
            // 'unreachable'. The UI branches on this without parsing the
            // human message.
            'error_class' => $i18nKey === 'storage.test.invalid_credentials'
                ? 'invalid_credentials'
                : 'unreachable',
            // Raw SDK error string. Never echoed in the API body — the
            // client only ever sees `error_class` and the translated
            // `message`. It is logged (see below) so a support ticket has
            // something concrete behind the generic user-facing wording.
            'detail' => $exception?->getMessage(),
        ];
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
