<?php

namespace App\Services\Server\Backups\Storage;

use Closure;
use Google\Client;
use Google\Service\Drive;
use Throwable;

/**
 * Asks Google where a folder actually lives.
 *
 * One question, because one question decides whether a Google Drive
 * destination can ever work: **is this folder inside a Shared Drive?**
 *
 * A service account has no Drive storage quota of its own. A file it uploads
 * to a personal ("My Drive") folder has no quota to charge, so Google refuses
 * the write with `storageQuotaExceeded` — even when the account is empty.
 * Files in a Shared Drive are owned by the drive rather than the uploader,
 * which is the only reason that case works.
 *
 * ⚠️ **This cannot be inferred from a successful write.** The failure is
 * quota-based, not permission-based, and quota is charged per *file*. A
 * 64-byte probe object may well be accepted into a shared personal folder that
 * a 4 GB archive is not — which would put a green tick on a destination whose
 * first real backup fails at 3am. `driveId` is a fact the API will state
 * plainly, so it is asked for rather than guessed at.
 *
 * The Drive API only reports `driveId` when explicitly told it is allowed to
 * see Shared Drives (`supportsAllDrives`). Without that parameter the field is
 * absent for *every* folder, and a check reading it would conclude that no
 * folder anywhere is in a Shared Drive — a check that always fails is as
 * useless as one that always passes, and harder to notice.
 */
class GoogleDriveFolder
{
    /** @var Closure(string): Drive */
    private Closure $factory;

    /**
     * @param  null|callable(string): Drive  $factory
     *                                                 Builds a Drive service from the service-account JSON.
     *                                                 Tests inject a fake so the suite never opens a socket.
     */
    public function __construct(?callable $factory = null)
    {
        $this->factory = $factory !== null
            ? Closure::fromCallable($factory)
            : static function (string $json): Drive {
                $client = new Client;
                $client->setAuthConfig(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
                // Read and write files the account itself created or was given
                // access to. Deliberately not the full `drive` scope: a backup
                // target has no business enumerating someone's whole Drive.
                $client->setScopes([Drive::DRIVE]);

                return new Drive($client);
            };
    }

    /**
     * What the API says about this folder.
     *
     * @return array{ok: bool, reason: string|null, drive_name: string|null}
     *                                                                       `ok` is true only for a folder in a Shared Drive. `reason` is an
     *                                                                       i18n key naming what is wrong, so the caller never has to
     *                                                                       interpret a Google error string.
     */
    public function inspect(string $serviceAccountJson, string $folderId): array
    {
        try {
            $drive = ($this->factory)($serviceAccountJson);

            $file = $drive->files->get($folderId, [
                'fields' => 'id,name,mimeType,driveId',
                // Without this the `driveId` field is never populated and the
                // check below would refuse every folder, including correct
                // ones. See the class docblock.
                'supportsAllDrives' => true,
            ]);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'reason' => $this->classify($e),
                'drive_name' => null,
            ];
        }

        // A folder id that resolves to a file is a copy-paste of the wrong
        // link. Backing up "into" a spreadsheet fails in a way that names
        // nothing, so it is caught here.
        if (method_exists($file, 'getMimeType') && $file->getMimeType() !== 'application/vnd.google-apps.folder') {
            return ['ok' => false, 'reason' => 'storage.test.drive_not_a_folder', 'drive_name' => null];
        }

        $driveId = method_exists($file, 'getDriveId') ? $file->getDriveId() : null;

        if (blank($driveId)) {
            // The whole reason this class exists.
            return ['ok' => false, 'reason' => 'storage.test.drive_personal', 'drive_name' => null];
        }

        return [
            'ok' => true,
            'reason' => null,
            'drive_name' => method_exists($file, 'getName') ? $file->getName() : null,
        ];
    }

    /**
     * The service account's own address, read out of the key file.
     *
     * Shown back in the UI because it is the address the operator has to share
     * the Drive folder *with* — having it on screen turns "why can't it see my
     * folder" into a one-line answer. An identifier, not a secret.
     */
    public function clientEmail(string $serviceAccountJson): ?string
    {
        try {
            $decoded = json_decode($serviceAccountJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) && is_string($decoded['client_email'] ?? null)
            ? $decoded['client_email']
            : null;
    }

    /**
     * Google's failures arrive with the reason in a JSON body rather than the
     * exception type, so the message is what there is to read. A malformed key
     * file never reaches the network at all and is its own answer.
     */
    private function classify(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if ($e instanceof \JsonException || str_contains($message, 'json')) {
            return 'storage.test.drive_bad_key';
        }

        if (str_contains($message, 'invalid_grant')
            || str_contains($message, 'invalid jwt')
            || str_contains($message, 'unauthorized_client')) {
            return 'storage.test.invalid_credentials';
        }

        if (str_contains($message, 'notfound') || str_contains($message, 'not found')) {
            return 'storage.test.drive_folder_missing';
        }

        if (str_contains($message, 'forbidden') || str_contains($message, 'permission')) {
            return 'storage.test.drive_not_shared';
        }

        return 'storage.test.unreachable';
    }
}
