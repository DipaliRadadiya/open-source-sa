<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\GoogleDriveFolder;
use Throwable;

/**
 * Google Drive, authenticated by a **service account** (no OAuth): one JSON key file and a
 * folder id, with no client secret, refresh token or callback route.
 *
 * ## ⚠️ It only works against a Workspace *Shared Drive*
 *
 * A service account has **zero Drive storage quota**, so a write into a personal ("My Drive")
 * folder is refused with `storageQuotaExceeded` even when the account is empty. Files in a
 * Shared Drive are owned by the drive, not the uploader. The constraint is surfaced in the
 * form, here, and in `preflight()`.
 *
 * **`preflight()` is the load-bearing one.** Writability is not a sufficient test: the failure
 * is quota-based, so a small sentinel may succeed where a 4 GB archive fails. Asking the API
 * where the folder lives has an unambiguous answer, so that is what gets asked.
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
     * Refuse a personal-Drive folder before any backup is scheduled against it, and record
     * which Shared Drive a good one belongs to.
     *
     * Returns a key rather than throwing: the prober would classify an exception as a
     * connection failure, but the connection is fine and the destination unusable.
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

        // Recorded, not re-derived: the row shows both, and neither is worth a round trip.
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
            // Resolved by the `google` driver registered in AppServiceProvider (Laravel has no
            // native Drive driver).
            'driver' => 'google',
            'service_account' => $destination->configValue('service_account_json'),
            'folder_id' => $destination->configValue('folder_id'),
            // The destination's prefix, as a path beneath the folder (Drive has real folders).
            'root' => trim((string) $destination->prefix, '/'),

            // Without it a failed write returns false and a missing backup looks like a good one.
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
            // The whole key file (about 2.3 KB), capped so a stray paste cannot fill the column.
            'config.service_account_json' => [...$secret, 'string', 'max:16384'],

            // Drive ids are opaque and URL-safe. The pattern refuses a pasted *folder URL* (the id
            // is the part after `/folders/`), which would fail much later with "file not found".
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
            // Recorded by a successful preflight, so the row shows the Shared Drive's name.
            // Not a credential.
            'drive_name' => $destination->configValue('drive_name'),
            // The service account's address, from the key file: what the folder must be shared
            // *with*. An identifier, not a secret.
            'client_email' => $destination->configValue('client_email'),
        ];
    }

    protected function categoryForType(Throwable $e): ?string
    {
        return null;
    }

    /**
     * Google's errors carry the real reason in a JSON body, so the exception type says little;
     * the reasons are matched by name (a documented, stable vocabulary).
     */
    protected function categoryForMessage(string $message): ?string
    {
        // The headline case: a personal Drive with a valid key. Not "unreachable" or "bad
        // credentials".
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

        // The id is right but the folder was never shared with the service account.
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
     * Nothing to repair: the location was not created by the panel.
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
     * Null, unlike the OAuth sibling: `webContentLink` authenticates by browser cookie, and
     * nobody is signed in as a service account. Making it work would mean sharing the file
     * more widely, which this driver must not do for a full copy of a site and its database.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string
    {
        return null;
    }

    /**
     * Null, unlike the OAuth sibling: this driver keeps `writeStream()` rather than carry a
     * second, differently-authenticated copy of the resumable uploader.
     */
    public function uploadFrom(StorageDestination $destination, string $key, string $path, ?callable $onProgress = null): bool
    {
        return false;
    }
}
