<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\GoogleDriveFolder;
use Throwable;

/**
 * Google Drive, authenticated by a **service account** — no OAuth.
 *
 * The interactive consent flow was ruled out (operator, 2026-09-14), which
 * settles the credential set: one JSON key file and a folder id. No client
 * id/secret, no refresh token, no callback route, no token rotation. It is by
 * some distance the simplest of the providers to configure.
 *
 * ## ⚠️ It only works against a Workspace *Shared Drive*
 *
 * A service account is its own principal and has **zero Drive storage quota**.
 * A file it uploads to a personal ("My Drive") folder has no quota to charge,
 * so Google refuses the write with `storageQuotaExceeded` — *even when the
 * account is completely empty*. Files in a Shared Drive are owned by the drive
 * rather than the uploader, which is the only reason that case works.
 *
 * There is no way around this without interactive OAuth. So the constraint is
 * surfaced in three places rather than left to be discovered: the form says it
 * before the key is pasted, `preflight()` refuses a personal folder before any
 * backup is scheduled against it, and this docblock explains why to whoever
 * reads it next.
 *
 * **`preflight()` is the load-bearing one.** Writability is not a sufficient
 * test here: the failure is quota-based, not permission-based, so a 64-byte
 * sentinel may well succeed into a shared personal folder where a 4 GB archive
 * does not — a green tick followed by a backup that fails at 3am. Asking the
 * API where the folder lives is a question with an unambiguous answer, so that
 * is what gets asked.
 */
class GoogleDriveDriver implements StorageDriver
{
    use ClassifiesFailures;

    public function provider(): StorageProvider
    {
        return StorageProvider::GoogleDrive;
    }

    public function __construct(private GoogleDriveFolder $folders) {}

    /**
     * Refuse a personal-Drive folder before any backup is ever scheduled
     * against it, and record which Shared Drive a good one belongs to.
     *
     * Returning a key rather than throwing: the prober's catch-all would
     * classify an exception as a connection failure, which is exactly the
     * wrong answer — the connection is fine, the destination is unusable.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        $json = (string) $destination->configValue('service_account_json', '');
        $folderId = (string) $destination->configValue('folder_id', '');

        if ($json === '' || $folderId === '') {
            return 'storage.test.drive_incomplete';
        }

        $result = $this->folders->inspect($json, $folderId);

        if (! $result['ok']) {
            return $result['reason'];
        }

        // Recorded, not re-derived: the row shows the Shared Drive's name and
        // the account address the folder must be shared with, and neither is
        // worth a round trip on every page load.
        $destination->mergeConfig(array_filter([
            'drive_name' => $result['drive_name'],
            'client_email' => $this->folders->clientEmail($json),
        ], fn ($v) => $v !== null));
        $destination->save();

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        return [
            // Resolved by the `google` driver registered in AppServiceProvider.
            // Laravel has no native Drive driver, and registering it there
            // rather than here keeps this class free of adapter construction.
            'driver' => 'google',
            'service_account' => $destination->configValue('service_account_json'),
            'folder_id' => $destination->configValue('folder_id'),
            // The destination's own prefix, as a path beneath the folder. Drive
            // has real folders, so this nests rather than being a key prefix.
            'root' => trim((string) $destination->prefix, '/'),

            // Same reason as every other driver: without it a failed write
            // returns false and a backup that never happened is
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
            // The whole key file. Capped generously — a Google service-account
            // key is about 2.3 KB — but capped, so a stray paste cannot fill
            // the column.
            'config.service_account_json' => [...$secret, 'string', 'max:16384'],

            // Drive ids are opaque, URL-safe and roughly 33 characters. The
            // pattern refuses a pasted *folder URL*, which is the mistake
            // people actually make: the id is the part after `/folders/`, and
            // storing the URL fails much later with a "file not found" that
            // names nothing useful.
            'config.folder_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return ['service_account_json'];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(StorageDestination $destination): array
    {
        return [
            'folder_id' => $destination->configValue('folder_id'),
            // Recorded by a successful preflight, so the row can show which
            // Shared Drive this points at rather than an opaque id. Never a
            // credential — a Shared Drive's name is visible to everyone who
            // can see the drive.
            'drive_name' => $destination->configValue('drive_name'),
            // The service account's own address, read out of the key file. It
            // is the thing the operator has to share the Drive folder *with*,
            // so showing it back turns "why can't it see my folder" into a
            // one-line answer. It is an identifier, not a secret.
            'client_email' => $destination->configValue('client_email'),
        ];
    }

    /**
     * Google's errors arrive as `Google\Service\Exception` with the real reason
     * in a JSON body, so the type alone says almost nothing. The reasons are
     * matched by name because they are a documented, stable vocabulary —
     * unlike the human messages beside them.
     */
    protected function categoryForType(Throwable $e): ?string
    {
        return null;
    }

    protected function categoryForMessage(string $message): ?string
    {
        // The headline case. A service account writing to a personal Drive
        // hits this with an empty account and a valid key, so reporting it as
        // "unreachable" or "bad credentials" would send the operator to fix
        // two things that are not wrong.
        if (str_contains($message, 'storagequotaexceeded')
            || str_contains($message, 'quota exceeded')) {
            return 'storage.test.drive_quota';
        }

        if (str_contains($message, 'invalid_grant')
            || str_contains($message, 'invalid jwt')
            || str_contains($message, 'unauthorized_client')
            || str_contains($message, 'invalid_client')) {
            return 'storage.test.invalid_credentials';
        }

        // The folder id is right but the service account was never given
        // access to it — the single most common setup mistake, and completely
        // different from a wrong id.
        if (str_contains($message, 'insufficientfilepermissions')
            || str_contains($message, 'forbidden')) {
            return 'storage.test.drive_not_shared';
        }

        if (str_contains($message, 'notfound')
            || str_contains($message, 'file not found')) {
            return 'storage.test.drive_folder_missing';
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
     * Null, unlike the OAuth sibling, and the difference is *whose* Drive it is.
     *
     * `webContentLink` authenticates by browser cookie. The sibling can use it
     * because the archive sits in the operator's own Drive and their browser is
     * already signed into that account. A service account is not a person and
     * nobody can be signed in as one, so the same link would refuse them — and
     * the only way to make it work would be to share the file more widely,
     * which for a full copy of a site and its database is not a trade this
     * driver gets to make on the operator's behalf.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string
    {
        return null;
    }

    /**
     * Null, unlike the OAuth sibling. A service account has no storage quota of
     * its own, so this driver only works against a Shared Drive; rather than
     * carry a second, differently-authenticated copy of the resumable uploader
     * for a path that cannot be exercised here, it keeps `writeStream()`.
     */
    public function uploadFrom(StorageDestination $destination, string $key, string $path, ?callable $onProgress = null): bool
    {
        return false;
    }
}
