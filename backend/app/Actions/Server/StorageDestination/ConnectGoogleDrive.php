<?php

namespace App\Actions\Server\StorageDestination;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\Storage\GoogleDriveWorkspace;
use App\Services\Server\Backups\Storage\GoogleOauthRedirect;
use App\Services\Server\Backups\Storage\GoogleOauthState;
use Illuminate\Validation\ValidationException;

/**
 * Drives the two halves of a redirect connection: send the operator to Google,
 * then turn the code they come back with into a stored refresh token.
 *
 * **The destination is read from `state`, never from the request:** a query parameter can be
 * written by anyone. The sealed, single-use `state` is the only thing that carries identity;
 * see {@see GoogleOauthState}.
 *
 * Nothing is written to the destination row until Google has answered, so merely *starting* a
 * reconnection never disturbs a working refresh token.
 */
class ConnectGoogleDrive
{
    public function __construct(
        private GoogleOauthRedirect $redirect,
        private GoogleOauthState $state,
        private GoogleDriveWorkspace $workspace,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Where to send the operator.
     *
     * Does not return the redirect URI: it is panel-wide, needed before this endpoint is
     * reachable, and served with the destinations list (one source for a string Google compares
     * byte for byte).
     *
     * @return array{authorize_url: string}
     */
    public function start(StorageDestination $destination): array
    {
        $this->guardProvider($destination);

        // Checked first: an unset panel origin gives a relative redirect URI that Google rejects
        // as a generic `invalid_request`, which reads like a bad client id.
        if (! $this->redirect->configured()) {
            $this->fail('storage.oauth.panel_url_missing');
        }

        $clientId = (string) $destination->configValue('client_id', '');

        if ($clientId === '') {
            $this->fail('storage.oauth.bad_client');
        }

        // Drop any attempt in flight: the second Connect click wins, no two callbacks race.
        $this->state->forget($destination);

        return [
            'authorize_url' => $this->redirect->authorizeUrl($clientId, $this->state->issue($destination)),
        ];
    }

    /**
     * Finish the round trip.
     *
     * @return array{status: string, reason: string|null, destination: StorageDestination|null}
     */
    public function complete(string $code, string $state): array
    {
        $consumed = $this->state->consume($state);

        if (! $consumed['ok']) {
            return $this->result('failed', (string) $consumed['reason']);
        }

        $destination = StorageDestination::find($consumed['destination_id']);

        // Deleted between approving and returning; fail cleanly instead of a 500.
        if ($destination === null) {
            return $this->result('failed', 'storage.oauth.destination_missing');
        }

        if ($destination->provider !== StorageProvider::GoogleDriveOauth) {
            return $this->result('failed', 'storage.oauth.wrong_provider');
        }

        $exchanged = $this->redirect->exchangeCode(
            (string) $destination->configValue('client_id', ''),
            (string) $destination->configValue('client_secret', ''),
            $code,
        );

        if (! $exchanged['ok']) {
            return $this->result('failed', (string) $exchanged['reason'], $destination);
        }

        return $this->store($destination, (string) $exchanged['refresh_token']);
    }

    /**
     * @return array{status: string, reason: string|null, destination: StorageDestination|null}
     */
    private function store(StorageDestination $destination, string $refreshToken): array
    {
        $prepared = $this->workspace->prepare(
            (string) $destination->configValue('client_id', ''),
            (string) $destination->configValue('client_secret', ''),
            $refreshToken,
            $this->workspace->folderName($destination),
        );

        if (! $prepared['ok']) {
            // The grant is real but no folder could be made (a full Drive?). Storing the token
            // anyway would look connected and fail every backup.
            return $this->result('failed', (string) $prepared['reason'], $destination);
        }

        // One update: `config` is an encrypted document, so two writes would overwrite each other.
        $destination->config = array_merge($destination->config ?? [], [
            'refresh_token' => $refreshToken,
            'folder_id' => $prepared['folder_id'],
            'account_email' => $prepared['account_email'],
        ]);

        // Connecting replaces the grant and folder, so the stored test verdict is about the old
        // pair. Cleared, not set to success: the probe also writes, reads back and deletes a
        // sentinel object, which this has not done.
        $destination->forceFill([
            'last_tested_at' => null,
            'last_test_success' => null,
            'last_test_error' => null,
        ]);

        $destination->save();

        $this->activityLogger->log('storage_destination.connected', $destination, [
            'name' => $destination->name,
            // The address, never the token: the activity log is readable by many.
            'account_email' => $prepared['account_email'],
        ]);

        return $this->result('connected', null, $destination);
    }

    private function guardProvider(StorageDestination $destination): void
    {
        if ($destination->provider !== StorageProvider::GoogleDriveOauth) {
            $this->fail('storage.oauth.wrong_provider');
        }
    }

    /**
     * @return array{status: string, reason: string|null, destination: StorageDestination|null}
     */
    private function result(string $status, ?string $reason, ?StorageDestination $destination = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'destination' => $destination];
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['provider' => [__($key)]]);
    }
}
