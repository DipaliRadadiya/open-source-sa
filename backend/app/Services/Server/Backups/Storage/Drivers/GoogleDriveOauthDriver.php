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
 * The sibling {@see GoogleDriveDriver} uses a service-account key. A service account has no Drive
 * quota of its own, so it needs a Workspace Shared Drive, which a free Gmail account cannot have.
 * Here the operator grants access to their own account, so their own quota pays for the backups.
 * Consequences:
 *
 * - **No folder id.** `drive.file` scopes access to files this app created, so the panel makes
 *   its own folder and keeps the id.
 * - **No Shared Drive check.** `GoogleDriveFolder` only proves a folder lives in a Shared Drive,
 *   a service-account problem.
 * - **A token that can die.** A refresh token can be revoked, and Google expires it after about a
 *   week if the OAuth app was left in "Testing". `preflight()` proves the grant is *alive*, not
 *   merely present.
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
     * A stored refresh token is only a string that was valid once (an OAuth app left in "Testing"
     * has them expire after about seven days), so the only proof is asking Google for an access token.
     *
     * **Two questions, not one:** a stored folder id also resolved only once. The folder lives in
     * somebody's *personal* Drive and may be deleted without telling the panel, after which the
     * token still refreshes and every upload fails.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        $clientId = (string) $destination->configValue('client_id', '');
        $clientSecret = (string) $destination->configValue('client_secret', '');
        $refreshToken = (string) $destination->configValue('refresh_token', '');

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            // Not connected yet: its own answer, not a Google failure.
            return 'storage.oauth.not_connected';
        }

        $result = $this->tokens->accessToken($clientId, $clientSecret, $refreshToken);

        if (! $result['ok']) {
            return $result['reason'];
        }

        // A live grant is not a working destination: check the folder still exists.
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
     * The panel created the folder and still holds a working refresh token, so it can create
     * another without a full Google re-consent (a 3am backup cannot complete a browser round trip).
     *
     * **It does not pretend nothing happened:** archives in the deleted folder are gone, their
     * backup rows stay, and restoring one still fails. Healing restores the ability to take *new*
     * backups, and writes an activity row so the replacement is visible.
     */
    public function heal(StorageDestination $destination): void
    {
        $clientId = (string) $destination->configValue('client_id', '');
        $clientSecret = (string) $destination->configValue('client_secret', '');
        $refreshToken = (string) $destination->configValue('refresh_token', '');

        // Never connected: no grant to build a folder with.
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return;
        }

        $folderId = (string) $destination->configValue('folder_id', '');

        // The common case: one metadata call answers "yes" and stops.
        if ($folderId !== '' && $this->workspace->folderExists($clientId, $clientSecret, $refreshToken, $folderId)['ok']) {
            return;
        }

        $prepared = $this->workspace->prepare(
            $clientId,
            $clientSecret,
            $refreshToken,
            $this->workspace->folderName($destination),
        );

        // Could not make one either (revoked grant, full Drive, API off): leave the destination
        // untouched and let the calling operation report the failure; `prepare()` already logged it.
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
            // The id, never the token.
            'folder_id' => $prepared['folder_id'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(StorageDestination $destination): array
    {
        return [
            // Resolved by the `google_oauth` disk registered in AppServiceProvider; separate from
            // `google` only because the client is authenticated differently.
            'driver' => 'google_oauth',
            'client_id' => $destination->configValue('client_id'),
            'client_secret' => $destination->configValue('client_secret'),
            'refresh_token' => $destination->configValue('refresh_token'),

            // The folder this panel created, recorded at connect time. Empty means "make one":
            // under `drive.file` we cannot see anything we did not create.
            'folder_id' => $destination->configValue('folder_id'),
            'root' => trim((string) $destination->prefix, '/'),

            // As in every driver: without it a failed write returns false and looks like success.
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
            // Google client ids end in a fixed suffix; matching it rejects a pasted *project id*
            // or a truncated copy at the form.
            'config.client_id' => ['required', 'string', 'max:255', 'regex:/\.apps\.googleusercontent\.com$/'],
            'config.client_secret' => [...$secret, 'string', 'max:255'],

            // Written by the connect flow, never typed. `sometimes` because the destination is
            // created *before* anyone has approved anything.
            'config.refresh_token' => ['sometimes', 'string', 'max:2048'],
            'config.folder_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        // The refresh token mints access tokens for as long as the grant lives: it is a secret
        // like the client secret.
        return ['client_secret', 'refresh_token'];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(StorageDestination $destination): array
    {
        return [
            // An identifier, not a secret; shown back so someone can confirm which Google project it belongs to.
            'client_id' => $destination->configValue('client_id'),

            // Which account approved this ("backups are going into *whose* Drive").
            'account_email' => $destination->configValue('account_email'),

            // Whether anyone has approved yet, without shipping the token that proves it.
            'connected' => filled($destination->configValue('refresh_token')),
        ];
    }

    protected function categoryForType(Throwable $e): ?string
    {
        return null;
    }

    protected function categoryForMessage(string $message): ?string
    {
        // Revoked at myaccount.google.com, or an app left in "Testing" past its week. Reporting it
        // as bad credentials would send the operator to re-check a correct client id.
        if (str_contains($message, 'invalid_grant')) {
            return 'storage.oauth.revoked';
        }

        if (str_contains($message, 'invalid_client')
            || str_contains($message, 'unauthorized_client')) {
            return 'storage.test.invalid_credentials';
        }

        // The user's own Drive is full: a real quota they can go and clear.
        if (str_contains($message, 'storagequotaexceeded')
            || str_contains($message, 'quota exceeded')) {
            return 'storage.oauth.user_quota';
        }

        // Under `drive.file` a file we did not create looks like one that does not exist. "Not
        // found" is honest; "forbidden" would imply a permission the operator could grant.
        if (str_contains($message, 'notfound')
            || str_contains($message, 'file not found')) {
            return 'storage.oauth.folder_missing';
        }

        return null;
    }

    /**
     * Upload with resume, because `writeStream()` cannot survive a bad chunk.
     *
     * The adapter's loop has no retry and reports failure as `false` ("Not able to write the
     * file"), so a momentary 5xx loses a long upload (a 100 GB archive is ~1048 chunks).
     *
     * Returns false if anything about the destination is not ready, which hands the caller back
     * to `writeStream()` rather than failing outright.
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
     * Drive's `webContentLink` authenticates by cookie, so it works only for the signed-in owner:
     * no sharing, no public link, no credential in a URL. Signed into the wrong Google account,
     * the operator gets Google's permission page. That is the correct failure: widening
     * permissions would publish a full copy of the site and its database.
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
