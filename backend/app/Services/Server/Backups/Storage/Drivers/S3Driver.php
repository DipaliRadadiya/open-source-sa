<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Rules\SafeProviderHost;
use App\Rules\SingleLine;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\TransferException;
use Throwable;

/**
 * Any S3-compatible service: AWS, Cloudflare R2, Backblaze B2, Wasabi,
 * DigitalOcean Spaces, or a self-hosted one.
 *
 * This is the original — and until now the only — destination driver. Its
 * config is carried over unchanged from `DestinationDisk`, including the two
 * flags whose defaults are wrong and whose docblocks are the reason they are
 * set explicitly here.
 */
class S3Driver implements StorageDriver
{
    use ClassifiesFailures;

    public function provider(): StorageProvider
    {
        return StorageProvider::S3;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        $endpoint = (string) $destination->configValue('endpoint', '');

        return [
            'driver' => 's3',
            'key' => $destination->configValue('access_key'),
            'secret' => $destination->configValue('secret_key'),
            'region' => $destination->configValue('region') ?: 'us-east-1',
            'bucket' => $destination->configValue('bucket'),
            'endpoint' => $endpoint !== '' ? $endpoint : null,

            // Behind the prefix, so one destination shared by several
            // applications cannot read or overwrite another's artefacts.
            'root' => $destination->prefix ?: '',

            // Path-style only for a custom endpoint. Wasabi, B2 and the
            // self-hosted services route through the path; real AWS deprecated
            // it and does not support it for buckets in regions launched after
            // 2019 — and an empty endpoint *means* AWS.
            'use_path_style_endpoint' => $endpoint !== '',

            // MUST stay true. With `throw => false` the adapter swallows
            // failures and returns null/false, so an upload that never
            // happened looks identical to one that did — a backup reporting
            // success over an empty bucket.
            'throw' => true,

            // MUST stay true. Laravel defaults this to *false* and so
            // overrides Flysystem's own `true`, which leaves `@http.stream`
            // unset on GetObject: Guzzle then buffers the whole object into
            // memory and hands readStream() a stream over an already-loaded
            // body. DownloadArtifact's stream_copy_to_stream looks streamed
            // and isn't — a 5.8 GB archive OOMs the worker on exactly the
            // large sites that most need restoring.
            'stream_reads' => true,
        ];
    }

    /**
     * Nothing to check up front: for this provider a successful write really
     * does mean the destination works, so the round trip is the whole test.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(bool $requireSecrets = true): array
    {
        $secret = $requireSecrets ? ['required'] : ['sometimes', 'required'];

        return [
            // Endpoint is optional: S3-compatible providers (AWS, R2, B2,
            // Wasabi, …) carry their region inside the bucket host. When
            // set, it must be a reachable https URL and must not point at
            // loopback or the cloud metadata range.
            'config.endpoint' => ['nullable', 'string', 'max:255', new SafeProviderHost(
                'storage.test.invalid_endpoint',
                'storage.test.forbidden_host',
            )],
            'config.region' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'config.bucket' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', new SingleLine],

            // Sizes are generous but capped to keep a stray paste of a
            // megabyte log from filling the database.
            'config.access_key' => [...$secret, 'string', 'max:255'],
            'config.secret_key' => [...$secret, 'string', 'max:512'],
        ];
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return ['access_key', 'secret_key'];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(StorageDestination $destination): array
    {
        return [
            'endpoint' => $destination->configValue('endpoint'),
            'region' => $destination->configValue('region'),
            'bucket' => $destination->configValue('bucket'),
        ];
    }

    /**
     * Every S3 error code that means "these keys are wrong or not allowed".
     *
     * Asked of the provider's own error code, not of the exception class: the
     * SDK throws one `S3Exception` for every failure, so the class says only
     * that S3 answered, never what it said.
     */
    private const CREDENTIAL_CODES = [
        'InvalidAccessKeyId',
        'SignatureDoesNotMatch',
        'AccessDenied',
        'InvalidToken',
        'ExpiredToken',
        'InvalidSecurity',
    ];

    /**
     * Codes AWS answers with when the bucket exists in another region.
     * Measured: a PUT signed for us-east-1 against an ap-south-1 bucket is
     * `AuthorizationHeaderMalformed` (400), which reads like a credentials
     * problem and is not one.
     */
    private const REGION_CODES = [
        'AuthorizationHeaderMalformed',
        'PermanentRedirect',
        'IllegalLocationConstraintException',
    ];

    /**
     * cURL errors that mean the TLS handshake or certificate failed: the
     * server was reached, but no request was ever sent. 35 is a broken
     * handshake, 60 an untrusted certificate (self-signed, expired, wrong name).
     */
    private const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 77, 80, 82, 83, 90, 91];

    /**
     * Classify by what the provider or cURL actually reported.
     *
     * This used to answer "credentials" for any `Aws\S3\Exception`, and every
     * S3 failure is one: a wrong bucket, a wrong region and a server whose TLS
     * is broken all told the user their keys were wrong, sending them to
     * replace keys that worked. Measured against real AWS and a real broken
     * host, 2026-09-24.
     *
     * The chain walk asks each link in turn. An `S3Exception` for a network
     * failure carries no error code, so it answers null here and the Guzzle
     * exception it wraps answers instead, from its cURL errno.
     *
     * One case stays "credentials" on purpose: Backblaze B2 keys belong to one
     * region, and an endpoint in another region answers `InvalidAccessKeyId`
     * ("the key is not valid"). Nothing in that answer says region, so calling
     * it one would be a guess.
     */
    protected function categoryForType(Throwable $e): ?string
    {
        if ($e instanceof AwsException) {
            $code = $e->getAwsErrorCode();

            return match (true) {
                in_array($code, self::CREDENTIAL_CODES, true) => 'storage.test.invalid_credentials',
                $code === 'NoSuchBucket' => 'storage.test.bucket_not_found',
                in_array($code, self::REGION_CODES, true) => 'storage.test.wrong_region',
                default => null,
            };
        }

        if ($e instanceof TransferException && method_exists($e, 'getHandlerContext')) {
            $errno = $e->getHandlerContext()['errno'] ?? null;

            if (in_array($errno, self::TLS_ERRNOS, true)) {
                return 'storage.test.tls_failed';
            }
        }

        return null;
    }

    protected function categoryForMessage(string $message): ?string
    {
        if (str_contains($message, 'invalidaccesskeyid')
            || str_contains($message, 'invalid access key')
            || str_contains($message, 'signature')
            || str_contains($message, 'access denied')
            || str_contains($message, '403')) {
            return 'storage.test.invalid_credentials';
        }

        return null;
    }

    /**
     * Nothing to repair: this destination's location is not something the panel
     * created, so its absence is somebody else's decision to undo.
     */
    public function heal(StorageDestination $destination): void {}

    /**
     * No special path needed: this driver's `readStream()` genuinely streams,
     * so the caller's copy never holds the whole archive anywhere.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool
    {
        return false;
    }

    /**
     * Null, because S3 already has a better answer.
     *
     * The adapter implements Flysystem's `TemporaryUrlGenerator`, so
     * `BackupController::download()` falls through to `temporaryUrl()` and gets
     * a signed, expiring link — narrower than anything this method could
     * return, since it carries its own expiry rather than relying on who the
     * browser is signed in as.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string
    {
        return null;
    }

    /**
     * Null: the S3 adapter's `writeStream()` already does multipart uploads
     * with the SDK's own retry behind it. Reimplementing that here would
     * replace a well-tested path with a worse one.
     */
    public function uploadFrom(StorageDestination $destination, string $key, string $path, ?callable $onProgress = null): bool
    {
        return false;
    }
}
