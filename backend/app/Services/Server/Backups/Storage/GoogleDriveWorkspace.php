<?php

namespace App\Services\Server\Backups\Storage;

use App\Models\StorageDestination;
use Closure;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;
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
                $client->setScopes([GoogleOauthTokens::SCOPE]);
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
            return $this->failure($this->classify($e), $e);
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
     * What to call the folder, in one place.
     *
     * Both the connect flow and the self-heal create this folder, and two
     * copies of the naming would drift the first time one of them learned
     * something — which is exactly how the name came to say "Laravel".
     *
     * **`branding.name`, not `app.name`.** `app.name` is the Laravel framework
     * setting and ships as the literal string "Laravel", which is what a real
     * install put on a folder in somebody's personal Google Drive.
     *
     * The panel's host goes in it too: one Drive can hold backups from several
     * panels, and a folder sitting next to someone's photos has to say which
     * machine it belongs to without being opened.
     */
    public function folderName(StorageDestination $destination): string
    {
        $brand = trim((string) config('branding.name')) ?: 'ServerAvatar';
        $host = trim((string) parse_url((string) config('server.storage.panel_url', ''), PHP_URL_HOST));

        $name = $brand.' Backups';

        if ($host !== '') {
            $name .= ' ('.$host.')';
        }

        return $name.' — '.$destination->name;
    }

    /**
     * Is the folder this destination points at still there?
     *
     * Asked at preflight, before a backup is scheduled, because the folder is
     * in somebody's *personal* Drive and they are entitled to delete it without
     * telling the panel. A stored id is not evidence the folder exists — it is
     * an id that resolved once.
     *
     * **Trashed counts as gone, and that is the case worth paying an API call
     * for.** Drive still resolves a trashed folder by id, so without this check
     * uploads keep succeeding into the Trash and are purged about thirty days
     * later. A destination reporting success while its archives quietly expire
     * is worse than one that fails, because nothing ever asks for them until
     * the day they are needed.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function folderExists(string $clientId, string $clientSecret, string $refreshToken, string $folderId): array
    {
        if (trim($folderId) === '') {
            return ['ok' => false, 'reason' => 'storage.oauth.not_connected'];
        }

        try {
            $drive = ($this->factory)($clientId, $clientSecret, $refreshToken);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'storage.oauth.revoked'];
        }

        try {
            $folder = $drive->files->get($folderId, ['fields' => 'id,trashed']);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => $this->classify($e)];
        }

        if (method_exists($folder, 'getTrashed') && $folder->getTrashed()) {
            return ['ok' => false, 'reason' => 'storage.oauth.folder_missing'];
        }

        return ['ok' => true, 'reason' => null];
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
     * Why Drive refused to make the folder.
     *
     * Previously this was one substring test for a full Drive and a catch-all
     * that said "check you have space" — advice which is simply wrong for every
     * cause but that one, and which sent the first real user to look at a Drive
     * that had plenty of room.
     *
     * The cause that actually happens is the **Drive API not being enabled** in
     * the Cloud project. It is invisible until this exact moment, because OAuth
     * is a different service: consent succeeds, a refresh token is issued, and
     * then the first Drive call 403s. The setup guide already calls it "the most
     * common mistake" — so it deserves its own sentence rather than a shrug.
     */
    private function classify(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        // The user's own Drive is full. A real quota belonging to a real person
        // who can go and clear it.
        if (str_contains($message, 'storagequotaexceeded')
            || str_contains($message, 'quota exceeded')) {
            return 'storage.oauth.user_quota';
        }

        // Google words this several ways across surfaces; match the stable
        // parts rather than a sentence it is free to rewrite.
        if (str_contains($message, 'accessnotconfigured')
            || str_contains($message, 'service_disabled')
            || str_contains($message, 'has not been used in project')
            || str_contains($message, 'is disabled')) {
            return 'storage.oauth.api_disabled';
        }

        // The grant exists but does not carry `drive.file` — an OAuth client
        // whose consent screen was configured with different scopes.
        if (str_contains($message, 'insufficient')
            && (str_contains($message, 'scope') || str_contains($message, 'permission'))) {
            return 'storage.oauth.insufficient_scope';
        }

        // Deleted from the Drive itself. Under `drive.file` a file we did not
        // create is indistinguishable from one that does not exist, so this is
        // also what a folder created by a *different* install looks like —
        // "reconnect and the panel will make a new one" is right for both.
        if (str_contains($message, 'notfound')
            || str_contains($message, 'not found')
            || str_contains($message, 'file not found')) {
            return 'storage.oauth.folder_missing';
        }

        return 'storage.oauth.folder_failed';
    }

    /**
     * @return array{ok: bool, folder_id: null, account_email: null, reason: string}
     */
    private function failure(string $reason, ?Throwable $e = null): array
    {
        if ($e !== null) {
            // The panel's sentence is for the operator; Google's is for whoever
            // has to work out why. Losing the second is what made the first real
            // failure of this feature a guess — the classifier above can only
            // name causes somebody thought of, and this is how the next one gets
            // added. Logged as its own field so a redactor can target it.
            Log::warning('Google Drive workspace preparation failed.', [
                'feature' => 'storage',
                'provider' => 'google_drive_oauth',
                'error_class' => $reason,
                'detail' => $e->getMessage(),
            ]);
        }

        return ['ok' => false, 'folder_id' => null, 'account_email' => null, 'reason' => $reason];
    }
}
