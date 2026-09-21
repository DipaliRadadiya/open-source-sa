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

    /**
     * A precondition to check before the write/read/delete round trip, or null
     * when the provider has none.
     *
     * Exists because **writability is not always a sufficient test**. A Google
     * service account has no Drive quota of its own, so a 64-byte sentinel can
     * succeed into a personal folder where a real archive fails with
     * `storageQuotaExceeded` — the probe would go green and the first backup
     * would fail at 3am. The question "is this folder in a Shared Drive" has an
     * unambiguous answer, so it is asked directly rather than inferred from a
     * write.
     *
     * Returns the i18n key of the failure, or null if the destination passes.
     * Implementations must not throw for an ordinary failure — the prober's
     * catch-all would classify it, losing the specific reason this exists to
     * give.
     */
    public function preflight(StorageDestination $destination): ?string;

    /**
     * Put right anything about this destination the panel can fix on its own.
     *
     * Called before a destination is used, so it runs on a scheduled backup at
     * 3am as well as on a button press. Most providers have nothing to do: a
     * bucket is not something the panel created, and its absence is a decision
     * somebody made elsewhere.
     *
     * Google Drive is different. The panel creates its own folder there, the
     * folder lives in somebody's *personal* Drive where they are entitled to
     * delete it, and the panel still holds a working refresh token afterwards —
     * so it can simply make another. Demanding a full re-consent to replace a
     * folder we can create with the credential we already have is friction for
     * its own sake.
     *
     * Must be safe to call often and must not throw: a repair that cannot
     * happen leaves the destination exactly as it was, and the ordinary failure
     * path reports it.
     */
    public function heal(StorageDestination $destination): void;

    /**
     * Fetch one object straight onto local disk.
     *
     * Exists because `readStream()` is not always a stream. The FTP adapter
     * implements it as `fopen('php://temp')` + `ftp_fget`, which downloads the
     * **entire** object into a buffer before the caller sees a byte — and
     * `php://temp` spills into the system temp directory, which on a normal
     * install is a tmpfs of a couple of gigabytes. A 24 GB archive therefore
     * filled /tmp and the restore failed with "Unable to read file", while the
     * transfer itself was fine: the same download to /dev/null finished in 34
     * seconds.
     *
     * The panel had already decided this for the other direction —
     * `server.backups.working_dir` lives under `storage/` precisely because
     * "/tmp is cleared on reboot and is often a small tmpfs, and a
     * multi-gigabyte site archive would fill it". Backups honoured that;
     * restores went through /tmp anyway, via the adapter.
     *
     * Returning false means "I have no special way to do this", and the caller
     * falls back to the streaming copy — correct for the drivers whose
     * `readStream()` really does stream.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool;

    /**
     * A URL the operator's browser can fetch the archive from directly.
     *
     * `BackupController::download()` used to call `$disk->temporaryUrl()`
     * unconditionally. **Only the S3 adapter implements that**, so Download
     * answered a raw 500 — "This driver does not support creating temporary
     * URLs" — for FTP, SFTP and both Drive destinations: four providers out of
     * five, for as long as the feature has existed. It surfaced the day the
     * first Drive backup got far enough to be downloadable.
     *
     * Returning null means "I have no such URL", exactly as `downloadTo()`
     * returns false, and the caller then falls back to `temporaryUrl()` before
     * refusing with a named reason. A driver that cannot do this is a normal
     * state to be reported, not an exception to be thrown.
     *
     * **Never a link that makes the archive public.** The object is a complete
     * copy of a site and its database. An implementation that widens
     * permissions to produce a URL is trading the operator's data for
     * convenience they did not ask for; if the only way to produce a link is
     * to share the file, return null instead.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string;
}
