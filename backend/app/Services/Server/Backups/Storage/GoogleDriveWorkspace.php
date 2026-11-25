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
 * Makes the panel its own folder in a freshly connected Drive, and finds out whose Drive it is.
 *
 * The `drive.file` scope only reaches files this app created, so the panel creates the folder
 * at connect time and remembers the id. Runs once per connection, not per backup.
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
            // Not fatal: the address is only a label, and failing here would orphan the
            // folder just created.
            'account_email' => $this->accountEmail($drive),
            'reason' => null,
        ];
    }

    /**
     * What to call the folder; the connect flow and the self-heal both use this.
     *
     * Uses `branding.name`, not `app.name` (which ships as "Laravel"). The panel host is
     * included because one Drive can hold backups from several panels.
     */
    public function folderName(StorageDestination $destination): string
    {
        // No fallback: `config/branding.php` always returns a non-empty name. A literal fallback
        // would put the vendor's product name into a customer's personal Drive (WhiteLabelTest
        // forbids it in `app/`, comments included).
        $name = trim((string) config('branding.name')).' Backups';
        $host = trim((string) parse_url((string) config('server.storage.panel_url', ''), PHP_URL_HOST));

        if ($host !== '') {
            $name .= ' ('.$host.')';
        }

        return $name.' — '.$destination->name;
    }

    /**
     * Is the folder this destination points at still there?
     *
     * Asked at preflight: the user may delete the folder without telling the panel. Trashed counts
     * as gone, since Drive still resolves a trashed id and uploads would be purged after ~30 days.
     *
     * @return array{ok: bool, reason: string|null}
     */
    /**
     * A link the operator's own browser can download the archive from.
     *
     * `webContentLink` authenticates by browser cookie, so only the signed-in owner can use it and
     * nothing gets shared. `?alt=media` needs an Authorization header and proxying would pin a PHP
     * worker. Searched by name (it ends in the backup uid, unique under `drive.file`).
     *
     * Returns null on any failure; the caller reports "Download is not available".
     */
    public function downloadLink(string $clientId, string $clientSecret, string $refreshToken, string $key): ?string
    {
        $name = basename($key);

        if ($name === '') {
            return null;
        }

        try {
            $drive = ($this->factory)($clientId, $clientSecret, $refreshToken);

            $found = $drive->files->listFiles([
                'q' => sprintf("name = '%s' and trashed = false", str_replace("'", "\\'", $name)),
                'fields' => 'files(id,webContentLink,capabilities/canDownload)',
                'pageSize' => 2,
            ])->getFiles();
        } catch (Throwable $e) {
            Log::channel('server-ops')->warning('could not resolve a Drive download link.', [
                'feature' => 'storage',
                'detail' => $e->getMessage(),
            ]);

            return null;
        }

        // Exactly one, or none: two matches would mean a shared uid, and guessing is worse.
        if (count($found) !== 1) {
            return null;
        }

        $file = $found[0];

        // A Workspace policy can forbid download; the link would only lead to a Google error page.
        if ($file->getCapabilities()?->getCanDownload() === false) {
            return null;
        }

        return $file->getWebContentLink() ?: null;
    }

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
     * Read via the Drive `about` resource: `drive.file` lacks the profile scopes userinfo needs.
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
     * The common cause is the Drive API not being enabled in the Cloud project: OAuth and consent
     * succeed, and only the first Drive call returns 403.
     */
    private function classify(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        // The user's own Drive is full.
        if (str_contains($message, 'storagequotaexceeded')
            || str_contains($message, 'quota exceeded')) {
            return 'storage.oauth.user_quota';
        }

        // Google words this several ways; match the stable parts.
        if (str_contains($message, 'accessnotconfigured')
            || str_contains($message, 'service_disabled')
            || str_contains($message, 'has not been used in project')
            || str_contains($message, 'is disabled')) {
            return 'storage.oauth.api_disabled';
        }

        // The grant does not carry `drive.file` (consent screen set up with other scopes).
        if (str_contains($message, 'insufficient')
            && (str_contains($message, 'scope') || str_contains($message, 'permission'))) {
            return 'storage.oauth.insufficient_scope';
        }

        // Under `drive.file` a folder another install made looks the same as a deleted one;
        // "reconnect" fits both.
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
            // Keep Google's own message: the classifier only names known causes.
            // Its own field so a redactor can target it.
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
