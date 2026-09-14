<?php

namespace App\Contracts;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use Throwable;

/**
 * Everything that differs between one storage provider and another (strategy).
 *
 * The point of this interface is that **nothing downstream branches on the
 * provider**. `UploadArtifact`, `VerifyArtifact`, `PruneOldBackups`,
 * `DownloadArtifact` and the prober all take a destination and get a
 * filesystem; which service is on the other end is this layer's problem and
 * nobody else's.
 *
 * Provider knowledge that used to be spread across four places — a hardcoded
 * S3 config array, a hardcoded `'driver' => 's3'` in the Resource, a set of
 * S3-only validation rules, and a `classify()` that fingerprints AWS SDK
 * exception names — lives behind this one contract instead.
 */
interface StorageDriver
{
    public function provider(): StorageProvider;

    /**
     * The Laravel/Flysystem disk config for this destination.
     *
     * Built on demand and never registered in `filesystems.disks`: a
     * destination's credentials must not bleed into other code that resolves
     * a named disk, across queue workers, Octane tasks or a later request.
     *
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array;

    /**
     * Validation rules for this provider's `config.*` keys.
     *
     * Returned rather than applied: validation still *lives in* the
     * FormRequest, which composes these into its own `rules()`. The driver
     * only knows what its provider needs — it does not know whether this is a
     * create or a partial update, and it must not decide.
     *
     * @param  bool  $requireSecrets  False on a partial update, where an
     *                                omitted credential means "keep the
     *                                existing one" rather than "clear it".
     * @return array<string, mixed>
     */
    public function rules(bool $requireSecrets = true): array;

    /**
     * Which `config` keys hold secrets.
     *
     * Used to keep them out of API responses and logs, and to decide whether
     * a partial update is rotating a credential or merely renaming. Anything
     * not named here is addressing information and is safe to echo back.
     *
     * @return list<string>
     */
    public function secretKeys(): array;

    /**
     * The non-secret part of the config, for the API response.
     *
     * A destination has to be describable in a list — "which bucket?", "which
     * host?" — without ever putting a credential in the envelope.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(StorageDestination $destination): array;

    /**
     * Categorise a failure into one of the two stable public classes:
     * `invalid_credentials` or `unreachable`.
     *
     * Per-provider because the evidence is per-provider. The S3 SDK raises
     * `SignatureDoesNotMatch`; an FTP server answers `530`; SSH throws on
     * authentication with none of those words in it. A shared implementation
     * can only recognise one of the three, and silently calls the other two
     * "unreachable" — which sends someone to check their hostname when their
     * password is what is wrong.
     *
     * Return the i18n key, not the bare category.
     */
    public function classify(Throwable $e): string;
}
