<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Exceptions\BlockedHostException;
use App\Models\StorageDestination;
use App\Rules\SafeProviderHost;
use App\Rules\SingleLine;
use App\Support\RemoteHost;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\TransferException;
use Throwable;

/**
 * Any S3-compatible service: AWS, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces,
 * or a self-hosted one.
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

            // Behind the prefix, so applications sharing a destination cannot touch each other's files.
            'root' => $destination->prefix ?: '',

            // Path-style only for a custom endpoint: Wasabi, B2 and self-hosted services need it,
            // while AWS (empty endpoint) does not support it for newer regions.
            'use_path_style_endpoint' => $endpoint !== '',

            // Pinned to an address checked now: the endpoint was validated at save time, but a name
            // can later resolve elsewhere. A blocked one throws BlockedHostException before connecting.
            ...$this->pinned($endpoint),

            // MUST stay true: with `false` the adapter swallows failures, so a backup could report
            // success over an empty bucket.
            'throw' => true,

            // MUST stay true: Laravel defaults it to false, so Guzzle buffers the whole object in
            // memory and a multi-GB archive OOMs the worker.
            'stream_reads' => true,
        ];
    }

    /**
     * Only the endpoint's address; the write round trip is the rest of the test. Checked here as
     * well as in config() so the Test button says why.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        $endpoint = (string) $destination->configValue('endpoint', '');
        $host = $endpoint === '' ? null : parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && RemoteHost::resolvesToBlocked($host) ? 'storage.test.forbidden_host' : null;
    }

    /**
     * The SDK's curl option pinning a custom endpoint's host. Nothing for AWS or an unresolvable name.
     *
     * @return array<string, mixed>
     *
     * @throws BlockedHostException
     */
    private function pinned(string $endpoint): array
    {
        $pin = $endpoint === '' ? null : RemoteHost::pin($endpoint);

        return $pin === null ? [] : ['http' => ['curl' => [CURLOPT_RESOLVE => [$pin]]]];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(bool $requireSecrets = true): array
    {
        $secret = $requireSecrets ? ['required'] : ['sometimes', 'required'];

        return [
            // Optional. When set it must be a reachable https URL, not loopback or the cloud
            // metadata range.
            'config.endpoint' => ['nullable', 'string', 'max:255', new SafeProviderHost(
                'storage.test.invalid_endpoint',
                'storage.test.forbidden_host',
            )],
            'config.region' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'config.bucket' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', new SingleLine],

            // Capped so a stray large paste cannot fill the database.
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
     * Matched on the provider's error code, not the exception class: the SDK throws one
     * `S3Exception` for every failure.
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
     * Codes AWS answers with when the bucket exists in another region (e.g.
     * `AuthorizationHeaderMalformed`, which reads like a credentials problem but is not).
     */
    private const REGION_CODES = [
        'AuthorizationHeaderMalformed',
        'PermanentRedirect',
        'IllegalLocationConstraintException',
    ];

    /**
     * cURL errors for a failed TLS handshake or certificate: the server was reached but no request
     * was sent. 35 is a broken handshake, 60 an untrusted certificate.
     */
    private const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 77, 80, 82, 83, 90, 91];

    /**
     * Classify by what the provider or cURL actually reported.
     *
     * Not every `Aws\S3\Exception` means bad credentials (wrong bucket, region or broken TLS are
     * all one). A network failure carries no error code, so it answers null here and the wrapped
     * Guzzle exception answers from its cURL errno.
     *
     * One case stays "credentials" on purpose: a B2 key used against another region's endpoint
     * answers `InvalidAccessKeyId`, which says nothing about region.
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
            // The status line, never a bare `403`: the message holds the probe key's random UUID.
            || str_contains($message, '403 forbidden')) {
            return 'storage.test.invalid_credentials';
        }

        return null;
    }

    /**
     * Nothing to repair: the panel did not create this location.
     */
    public function heal(StorageDestination $destination): void {}

    /**
     * No special path needed: `readStream()` genuinely streams.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool
    {
        return false;
    }

    /**
     * Null: the adapter's `temporaryUrl()` (used by `BackupController::download()`) gives a
     * signed, expiring link, which is narrower than anything returned here.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string
    {
        return null;
    }

    /**
     * Null: the adapter's `writeStream()` already does multipart uploads with the SDK's retry.
     */
    public function uploadFrom(StorageDestination $destination, string $key, string $path, ?callable $onProgress = null): bool
    {
        return false;
    }
}
