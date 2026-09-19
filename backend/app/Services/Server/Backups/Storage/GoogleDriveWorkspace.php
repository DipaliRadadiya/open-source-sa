<?php

namespace App\Services\Server\Backups\Storage;

use Closure;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Throwable;

/**
 * Makes the panel its own folder in a freshly connected Drive, and finds out
 * whose Drive it is.
 *
 * Both jobs exist because of `drive.file`. That scope grants access only to
 * files this app created, which is what removes the folder-id field entirely:
 * there is nothing to browse, nothing to paste, and no way to point the panel
 * at somebody's existing folder even on purpose. So the panel creates one at
 * connect time and remembers the id.
 *
 * Runs once per connection, not per backup.
 */
class GoogleDriveWorkspace
{
    /** @var Closure(string, string, string): Drive */
    private Closure $factory;

    /**
     * @param  null|callable(string, string, string): Drive  $factory
     *                                                                 Builds a Drive service from client id, secret and refresh token.
     *                                                                 Tests inject a fake so the suite never opens a socket.
     */
    public function __construct(?callable $factory = null)
    {
        $this->factory = $factory !== null
            ? Closure::fromCallable($factory)
            : static function (string $clientId, string $clientSecret, string $refreshToken): Drive {
                $client = new Client;
                $client->setClientId($clientId);
                $client->setClientSecret($clientSecret);
                $client->setScopes([GoogleDeviceFlow::SCOPE]);
                $client->fetchAccessTokenWithRefreshToken($refreshToken);

                return new Drive($client);
            };
    }

    /**
     * Create the backup folder and read back who owns it.
     *
     * @return array{ok: bool, folder_id: string|null, account_email: string|null, reason: string|null}
     */
    public function prepare(string $clientId, string $clientSecret, string $refreshToken, string $folderName): array
    {
        try {
            $drive = ($this->factory)($clientId, $clientSecret, $refreshToken);
        } catch (Throwable) {
            return $this->failure('storage.oauth.revoked');
        }

        try {
            $folder = $drive->files->create(
                new DriveFile([
                    'name' => $folderName,
                    'mimeType' => 'application/vnd.google-apps.folder',
                ]),
                ['fields' => 'id'],
            );
        } catch (Throwable $e) {
            return $this->failure(
                str_contains(strtolower($e->getMessage()), 'storagequotaexceeded')
                    ? 'storage.oauth.user_quota'
                    : 'storage.oauth.folder_failed'
            );
        }

        $folderId = method_exists($folder, 'getId') ? (string) $folder->getId() : '';

        if ($folderId === '') {
            return $this->failure('storage.oauth.folder_failed');
        }

        return [
            'ok' => true,
            'folder_id' => $folderId,
            // Deliberately not fatal. The address is a label — "backups are
            // going into *this* account" — and losing it must not fail a
            // connection whose folder already exists. A connect that rolled
            // back here would leave an orphaned folder in someone's Drive and
            // report failure for a grant that works perfectly.
            'account_email' => $this->accountEmail($drive),
            'reason' => null,
        ];
    }

    /**
     * Whose Drive this is.
     *
     * Read through the Drive API's own `about` resource rather than the
     * userinfo endpoint, because `drive.file` does not grant the profile
     * scopes that endpoint needs — asking there would fail on every correctly
     * configured connection.
     */
    private function accountEmail(Drive $drive): ?string
    {
        try {
            $about = $drive->about->get(['fields' => 'user(emailAddress)']);
            $user = method_exists($about, 'getUser') ? $about->getUser() : null;
            $email = $user && method_exists($user, 'getEmailAddress') ? (string) $user->getEmailAddress() : '';

            return $email !== '' ? $email : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{ok: bool, folder_id: null, account_email: null, reason: string}
     */
    private function failure(string $reason): array
    {
        return ['ok' => false, 'folder_id' => null, 'account_email' => null, 'reason' => $reason];
    }
}
