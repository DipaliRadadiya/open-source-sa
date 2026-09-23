<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\StorageDriver;
use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\GoogleDriveWorkspace;
use App\Services\Server\Backups\Storage\GoogleOauthTokens;
use App\Services\Server\Backups\Storage\GoogleResumableUpload;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Drive as the *user*, not as a service account.
 *
 * The sibling driver, {@see GoogleDriveDriver}, authenticates with a
 * service-account key. A service account has no Drive storage quota of its own
 * and quota is charged to a file's owner, so everything it uploads is
 * unstorable unless a Workspace Shared Drive owns the files instead. Shared
 * Drives need Google Workspace, and Business Starter does not include them —
 * which means a free Gmail account cannot use that destination at all. Not
 * degraded: impossible.
 *
 * Here the operator grants access to their own account, so their own 15 GB
 * pays for the backups. Three things fall out of that:
 *
 * - **No folder id.** `drive.file` scopes access to files this app created, so
 *   the panel makes its own folder and keeps the id. Nothing to find, paste or
 *   get wrong — the question that started this whole feature.
 * - **No Shared Drive check.** `GoogleDriveFolder` exists solely to prove a
 *   folder lives in a Shared Drive, which is a service-account problem. There
 *   is nothing here for it to assert.
 * - **A token that can die.** A service-account key works until deleted; a
 *   refresh token can be revoked by the user, and Google expires it after about
 *   a week if the OAuth app was left in "Testing". `preflight()` therefore
 *   proves the grant is *alive*, not merely present.
 *
 * See `google-drive-oauth-design.md`.
 */
class GoogleDriveOauthDriver implements StorageDriver
{
    use ClassifiesFailures;

    public function __construct(
        private GoogleOauthTokens $tokens,
        private GoogleDriveWorkspace $workspace,
        private GoogleResumableUpload $resumable = new GoogleResumableUpload,
    ) {}

    public function provider(): StorageProvider
    {
        return StorageProvider::GoogleDriveOauth;
    }

    /**
     * Prove the grant still works before a backup is ever scheduled on it.
     *
     * A stored refresh token is not evidence of anything — it is a string that
     * was valid once. The single most likely production failure here is an
     * OAuth app left in "Testing", where Google expires refresh tokens after
     * roughly seven days: the destination works all week, then every backup
     * fails, and nothing in the panel changed. Asking Google for an access
     * token is the only way to tell the difference, and it costs one request.
     *
     * **Two questions, not one.** A stored folder id is the same kind of
     * evidence as a stored token: it resolved once. The folder lives in
     * somebody's *personal* Drive and they are entitled to delete it without
     * telling the panel — after which the token still refreshes perfectly and
     * every upload fails. Checking only the token therefore put a green tick on
     * precisely the destination whose next run breaks, which is the outcome
     * this method exists to prevent.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        $clientId = (string) $destination->configValue('client_id', '');
        $clientSecret = (string) $destination->configValue('client_secret', '');
        $refreshToken = (string) $destination->configValue('refresh_token', '');

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            // Not connected yet. Its own answer, and not a failure of Google's.
            return 'storage.oauth.not_connected';
        }

        $result = $this->tokens->accessToken($clientId, $clientSecret, $refreshToken);

        if (! $result['ok']) {
            return $result['reason'];
        }

        // A live grant is not a working destination. The folder sits in
        // somebody's personal Drive and they may delete it without telling the
        // panel — at which point the token still refreshes perfectly and every
        // upload fails. Checking the token alone put a green tick on exactly
        // the destination whose next real run breaks, which is the failure this
        // method exists to prevent.
        return $this->workspace->folderExists(
            $clientId,
            $clientSecret,
            $refreshToken,
            (string) $destination->configValue('folder_id', ''),
        )['reason'];
    }

    /**
     * Make a new backup folder when the old one is gone.
     *
     * The panel created that folder and still holds a working refresh token, so
     * it can create another — requiring a full Google re-consent to replace it
     * was friction for its own sake, and it left the destination stuck: the
     * failure said "connect again", and connecting again is a browser round
     * trip somebody has to notice and complete. A backup at 3am cannot do that.
     *
     * **What this deliberately does not do is pretend nothing happened.** The
     * archives that were in the deleted folder are gone, and a new empty folder
     * does not bring them back — their backup rows stay, and restoring one
     * still fails, correctly, because the archive really is missing. Healing
     * restores the destination's ability to take *new* backups; it makes no
     * claim about old ones, and it writes an activity row so the replacement is
     * a visible event rather than a silent one.
     */
    public function heal(StorageDestination $destination): void
    {
        $clientId = (string) $destination->configValue('client_id', '');
        $clientSecret = (string) $destination->configValue('client_secret', '');
        $refreshToken = (string) $destination->configValue('refresh_token', '');

        // Never connected. There is no grant to build a folder with, and
        // "not connected" is already the honest answer everywhere else.
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return;
        }

        $folderId = (string) $destination->configValue('folder_id', '');

        // The overwhelmingly common case, and the reason this is cheap: one
        // metadata call that answers "yes" and stops.
        if ($folderId !== '' && $this->workspace->folderExists($clientId, $clientSecret, $refreshToken, $folderId)['ok']) {
            return;
        }

        $prepared = $this->workspace->prepare(
            $clientId,
            $clientSecret,
            $refreshToken,
            $this->workspace->folderName($destination),
        );

        // Could not make one either — a revoked grant, a full Drive, the API
        // switched off. Leave the destination untouched and let the operation
        // that called this report the real failure; `prepare()` has already
        // logged Google's own words.
        if (! $prepared['ok']) {
            return;
        }

        $destination->config = array_merge($destination->config ?? [], [
            'folder_id' => $prepared['folder_id'],
            'account_email' => $prepared['account_email'] ?? $destination->configValue('account_email'),
        ]);

        // The stored verdict was about a folder that no longer exists.
        $destination->forceFill([
            'last_tested_at' => null,
            'last_test_success' => null,
            'last_test_error' => null,
        ]);

        $destination->save();

        Log::channel('server-ops')->info('Recreated a missing Google Drive backup folder.', [
            'feature' => 'storage',
            'destination_id' => $destination->getKey(),
            'destination_name' => $destination->name,
            // The id, never the token. Enough to match it against what is in
            // the Drive, and nothing that could be used to reach it.
            'folder_id' => $prepared['folder_id'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        return [
            // Resolved by the `google_oauth` disk registered in
            // AppServiceProvider. Separate from `google` because the client is
            // authenticated differently; everything below the client — the
            // adapter, path translation, streaming — is identical.
            'driver' => 'google_oauth',
            'client_id' => $destination->configValue('client_id'),
            'client_secret' => $destination->configValue('client_secret'),
            'refresh_token' => $destination->configValue('refresh_token'),

            // The folder this panel created for itself, recorded at connect
            // time. Empty means "make one" — under `drive.file` we cannot see
            // anything we did not create, so there is nothing else it could
            // mean.
            'folder_id' => $destination->configValue('folder_id'),
            'root' => trim((string) $destination->prefix, '/'),

            // Same reason as every other driver: without it a failed write
            // returns false, and a backup that never happened is
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
            // Google client ids are long and end in a fixed suffix. Matching it
            // rejects a pasted *project id* or a truncated copy at the form
            // rather than at approval time, when the operator has already
            // walked to their phone.
            'config.client_id' => ['required', 'string', 'max:255', 'regex:/\.apps\.googleusercontent\.com$/'],
            'config.client_secret' => [...$secret, 'string', 'max:255'],

            // Written by the connect flow, never typed. `sometimes` because the
            // destination is created *before* anyone has approved anything —
            // requiring it here would make connecting impossible.
            'config.refresh_token' => ['sometimes', 'string', 'max:2048'],
            'config.folder_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        // The refresh token is a credential in the fullest sense: it mints
        // access tokens on demand, for as long as the grant lives. It belongs
        // with the client secret, not beside the folder id.
        return ['client_secret', 'refresh_token'];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(StorageDestination $destination): array
    {
        return [
            // An identifier, not a secret — it is visible in every consent URL
            // the operator has already opened. Showing it back is what lets
            // someone confirm which Google project a destination belongs to.
            'client_id' => $destination->configValue('client_id'),

            // Which account approved this. The one thing an operator most
            // needs to see on a row: "backups are going into *whose* Drive".
            'account_email' => $destination->configValue('account_email'),

            // Whether anyone has approved yet, without ever shipping the token
            // that proves it. The form needs this to decide between showing a
            // Connect button and showing the connected account.
            'connected' => filled($destination->configValue('refresh_token')),
        ];
    }

    protected function categoryForType(Throwable $e): ?string
    {
        return null;
    }

    protected function categoryForMessage(string $message): ?string
    {
        // The one that will actually happen in the field. Revoked at
        // myaccount.google.com, or an app left in "Testing" past its week.
        // Reporting it as bad credentials would send the operator to re-check
        // a client id that is perfectly correct.
        if (str_contains($message, 'invalid_grant')) {
            return 'storage.oauth.revoked';
        }

        if (str_contains($message, 'invalid_client')
            || str_contains($message, 'unauthorized_client')) {
            return 'storage.test.invalid_credentials';
        }

        // The user's own Drive is full. Unlike the service-account driver, this
        // is a real quota belonging to a real person who can go and clear it.
        if (str_contains($message, 'storagequotaexceeded')
            || str_contains($message, 'quota exceeded')) {
            return 'storage.oauth.user_quota';
        }

        // Under `drive.file` a file we did not create is indistinguishable from
        // one that does not exist. Saying "not found" is honest; saying
        // "forbidden" would imply a permission the operator could go and grant.
        if (str_contains($message, 'notfound')
            || str_contains($message, 'file not found')) {
            return 'storage.oauth.folder_missing';
        }

        return null;
    }

    /**
     * Upload with resume, because `writeStream()` cannot survive a bad chunk.
     *
     * The adapter's loop has no retry and reports failure as `false`, which
     * Flysystem renders as "Not able to write the file" with nothing
     * underneath. At 100 GB that is ~1048 chances to lose an hour's work to a
     * momentary 5xx — and it happened on 2026-09-23 at chunk 35.
     *
     * Returns false if anything about the destination is not ready, which
     * hands the caller back to `writeStream()` rather than failing outright.
     */
    public function uploadFrom(
        StorageDestination $destination,
        string $key,
        string $path,
        ?callable $onProgress = null,
    ): bool {
        $clientId = (string) $destination->configValue('client_id', '');
        $clientSecret = (string) $destination->configValue('client_secret', '');
        $refreshToken = (string) $destination->configValue('refresh_token', '');

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return false;
        }

        return $this->resumable->upload(
            $clientId,
            $clientSecret,
            $refreshToken,
            (string) $destination->configValue('folder_id', ''),
            $key,
            $path,
            $onProgress,
        );
    }

    /**
     * The archive is in the operator's own Drive, so send them to it.
     *
     * This is the one driver that can answer, and it can answer precisely
     * because of what makes it different from its service-account sibling: the
     * files belong to a real person whose browser is already signed into that
     * Google account. Drive's `webContentLink` authenticates by cookie, so it
     * works for exactly that person and for nobody else — no sharing, no
     * public link, no credential in a URL.
     *
     * The consequence worth stating: signed into the wrong Google account, the
     * operator gets Google's permission page rather than the file. That is the
     * correct failure. The alternative — widening permissions so any holder of
     * the link could fetch it — would publish a complete copy of the site and
     * its database to buy a nicer error message.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string
    {
        return $this->workspace->downloadLink(
            (string) $destination->configValue('client_id', ''),
            (string) $destination->configValue('client_secret', ''),
            (string) $destination->configValue('refresh_token', ''),
            $key,
        );
    }

    /**
     * No special path needed: this driver's `readStream()` genuinely streams,
     * so the caller's copy never holds the whole archive anywhere.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool
    {
        return false;
    }
}
