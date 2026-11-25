<?php

namespace App\Contracts;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use Throwable;

/**
 * Everything that differs between one storage provider and another (strategy).
 *
 * **Nothing downstream branches on the provider**: `UploadArtifact`, `VerifyArtifact`,
 * `PruneOldBackups`, `DownloadArtifact` and the prober take a destination and get a filesystem.
 * All provider knowledge (config, validation rules, failure classification) lives behind this
 * contract.
 */
interface StorageDriver
{
    public function provider(): StorageProvider;

    /**
     * The Laravel/Flysystem disk config for this destination.
     *
     * Built on demand, never registered in `filesystems.disks`: a destination's credentials must
     * not bleed into other code that resolves a named disk.
     *
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array;

    /**
     * Validation rules for this provider's `config.*` keys.
     *
     * Returned, not applied: the FormRequest composes these into its own `rules()`. The driver
     * does not know whether this is a create or a partial update.
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
     * Used to keep them out of API responses and logs, and to tell a credential rotation from a
     * rename. Anything not named here is addressing information, safe to echo back.
     *
     * @return list<string>
     */
    public function secretKeys(): array;

    /**
     * The non-secret part of the config, for the API response.
     *
     * Describes a destination in a list ("which bucket?") without a credential in the envelope.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(StorageDestination $destination): array;

    /**
     * Categorise a failure into one of the two stable public classes:
     * `invalid_credentials` or `unreachable`.
     *
     * Per-provider because the evidence is: S3 raises `SignatureDoesNotMatch`, FTP answers `530`,
     * SSH throws on authentication. A shared implementation would call the unrecognised ones
     * "unreachable" and send someone to check the hostname when the password is wrong.
     *
     * Return the i18n key, not the bare category.
     */
    public function classify(Throwable $e): string;

    /**
     * A precondition to check before the write/read/delete round trip, or null
     * when the provider has none.
     *
     * **Writability is not always a sufficient test.** A Google service account has no Drive quota
     * of its own, so a small sentinel can succeed into a personal folder where a real archive fails
     * with `storageQuotaExceeded`. "Is this folder in a Shared Drive" is asked directly.
     *
     * Returns the i18n key of the failure, or null if the destination passes. Must not throw for
     * an ordinary failure: the prober's catch-all would lose the specific reason.
     */
    public function preflight(StorageDestination $destination): ?string;

    /**
     * Put right anything about this destination the panel can fix on its own.
     *
     * Called before a destination is used, including scheduled backups. Most providers have
     * nothing to do: a bucket was not created by the panel.
     *
     * Google Drive is different: the panel creates its own folder in somebody's *personal* Drive,
     * where it can be deleted, and the panel still holds a refresh token, so it can make another
     * without demanding a full re-consent.
     *
     * Must be safe to call often and must not throw: a repair that cannot happen leaves the
     * destination as it was, and the ordinary failure path reports it.
     */
    public function heal(StorageDestination $destination): void;

    /**
     * Fetch one object straight onto local disk.
     *
     * Exists because `readStream()` is not always a stream: the FTP adapter downloads the
     * **entire** object into `php://temp` first, which spills into /tmp (often a small tmpfs), so
     * a multi-gigabyte archive fails with "Unable to read file". Large archives belong under
     * `server.backups.working_dir` (in `storage/`), never /tmp.
     *
     * Returning false means "no special way", and the caller falls back to the streaming copy
     * (correct for drivers whose `readStream()` really streams).
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool;

    /**
     * A URL the operator's browser can fetch the archive from directly.
     *
     * **Only the S3 adapter implements `temporaryUrl()`**, so callers ask the driver first.
     *
     * Returning null means "no such URL" (as `downloadTo()` returns false); the caller falls back
     * to `temporaryUrl()` before refusing with a named reason. That is a normal state, not an
     * exception.
     *
     * **Never a link that makes the archive public.** The object is a complete copy of a site and
     * its database: if the only way to produce a link is to share the file, return null.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string;

    /**
     * Upload a local file with this provider's own resumable machinery.
     *
     * Returning false means "no special way" (as {@see self::downloadTo()}) and the caller falls
     * back to `writeStream()`. A driver that cannot resume should not pretend to.
     *
     * **Why this exists.** Flysystem's `writeStream()` is one shot: the Drive adapter loops chunks
     * with no retry, so one bad chunk discards the whole upload and the archiving before it. Google's
     * resumable protocol lets the session report its committed offset and carry on, turning a
     * transient blip into a pause.
     *
     * `$onProgress` receives the cumulative byte count after each chunk, keeping the progress and
     * the heartbeat the reaper reads warm across a retry.
     *
     * @param  null|callable(int): void  $onProgress
     */
    public function uploadFrom(
        StorageDestination $destination,
        string $key,
        string $path,
        ?callable $onProgress = null,
    ): bool;
}
