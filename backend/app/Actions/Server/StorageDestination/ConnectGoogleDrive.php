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
 * **The destination is read from `state`, never from the request.** Google
 * redirects a browser to a frontend page, which forwards `code` and `state` as
 * an authenticated call — and a page cannot be trusted to say which destination
 * the attempt was for, because that is a query parameter anyone can write. The
 * sealed, single-use `state` is the only thing here that carries identity; see
 * {@see GoogleOauthState}.
 *
 * Nothing about the attempt is written to the destination row until Google has
 * answered. A destination with a working refresh token must not be disturbed by
 * somebody merely *starting* a reconnection, and abandoned attempts are the
 * common case — people close the tab.
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
     * Deliberately does *not* return the redirect URI. It is panel-wide, it is
     * needed long before this endpoint is reachable — when the Google OAuth
     * client is created, before there is a client id to save — and it is served
     * with the destinations list instead. Returning it here as well would be a
     * second source for one string that Google compares byte for byte.
     *
     * @return array{authorize_url: string}
     */
    public function start(StorageDestination $destination): array
    {
        $this->guardProvider($destination);

        // Checked before Google is, because an unset panel origin produces a
        // relative redirect URI that Google rejects as a generic
        // `invalid_request` — which reads exactly like a bad client id and
        // sends the operator to re-check a value that is perfectly fine.
        if (! $this->redirect->configured()) {
            $this->fail('storage.oauth.panel_url_missing');
        }

        $clientId = (string) $destination->configValue('client_id', '');

        if ($clientId === '') {
            $this->fail('storage.oauth.bad_client');
        }

        // Any attempt already in flight is dropped. Pressing Connect twice
        // should mean the second link works and the first is dead, not that two
        // callbacks race to write the same row.
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

        // Deleted between approving and returning. Rare, but the alternative is
        // a 500 on a page the operator reached by doing everything right.
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
            $this->folderName($destination),
        );

        if (! $prepared['ok']) {
            // The grant is real but we could not make a folder in it — a full
            // Drive, most likely. Storing the token anyway would leave a
            // destination that looks connected and fails every backup.
            return $this->result('failed', (string) $prepared['reason'], $destination);
        }

        // Written as one update: `config` is an encrypted document, so two
        // separate writes would decrypt, merge and re-encrypt twice and the
        // second would overwrite the first's view of it.
        $destination->config = array_merge($destination->config ?? [], [
            'refresh_token' => $refreshToken,
            'folder_id' => $prepared['folder_id'],
            'account_email' => $prepared['account_email'],
        ]);
        $destination->save();

        $this->activityLogger->log('storage_destination.connected', $destination, [
            'name' => $destination->name,
            // The address, never the token. This row is readable by anyone who
            // can see the activity log.
            'account_email' => $prepared['account_email'],
        ]);

        return $this->result('connected', null, $destination);
    }

    /**
     * A name the operator will recognise in their own Drive, months later,
     * next to their holiday photos.
     *
     * **`branding.name`, not `app.name`.** `app.name` is the Laravel framework
     * setting and ships as the literal string "Laravel" — which is what a real
     * install put on a folder in somebody's personal Google Drive. `branding.name`
     * is the panel's own identity, the one already shown in its title bar and
     * its emails, and it defaults to something meaningful.
     *
     * The server's hostname goes in it too, because one Drive can hold backups
     * from several panels and "ServerAvatar Backups — Drive" would not say which
     * machine they came from. A folder in a personal Drive has to explain itself
     * without anyone opening it.
     */
    private function folderName(StorageDestination $destination): string
    {
        $brand = trim((string) config('branding.name')) ?: 'ServerAvatar';
        $host = trim((string) parse_url((string) config('server.storage.panel_url', ''), PHP_URL_HOST));

        $name = $brand.' Backups';

        if ($host !== '') {
            $name .= ' ('.$host.')';
        }

        return $name.' — '.$destination->name;
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
