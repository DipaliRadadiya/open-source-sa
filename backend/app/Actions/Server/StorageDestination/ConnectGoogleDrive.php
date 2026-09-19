<?php

namespace App\Actions\Server\StorageDestination;

use App\Enums\StorageProvider;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\Storage\GoogleDeviceFlow;
use App\Services\Server\Backups\Storage\GoogleDriveWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Drives the two halves of a device-flow connection: ask for a code, then ask
 * repeatedly whether it has been approved.
 *
 * **The device code lives in the cache, not in a column.** It is valid for
 * about half an hour, it is worthless afterwards, and a destination that
 * already has a working refresh token must not be altered by somebody merely
 * *starting* a reconnection. A column would also have to be cleaned up on
 * every abandoned attempt, and abandoned attempts are the common case — people
 * close the tab.
 *
 * Polling is the caller's job. One request asks one question, so an HTTP
 * worker is never held for the half hour a human might take.
 */
class ConnectGoogleDrive
{
    public function __construct(
        private GoogleDeviceFlow $flow,
        private GoogleDriveWorkspace $workspace,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array{user_code: string, verification_url: string, interval: int, expires_in: int}
     */
    public function start(StorageDestination $destination): array
    {
        $this->guardProvider($destination);

        $clientId = (string) $destination->configValue('client_id', '');

        if ($clientId === '') {
            $this->fail('storage.oauth.bad_client');
        }

        $result = $this->flow->start($clientId);

        if (! $result['ok']) {
            $this->fail((string) $result['reason']);
        }

        Cache::put(
            $this->cacheKey($destination),
            $result['device_code'],
            // Google's own lifetime, so a stale code cannot outlive the one
            // the operator is looking at.
            now()->addSeconds($result['expires_in']),
        );

        return [
            'user_code' => (string) $result['user_code'],
            'verification_url' => (string) $result['verification_url'],
            'interval' => $result['interval'],
            'expires_in' => $result['expires_in'],
        ];
    }

    /**
     * Ask once whether the operator has approved.
     *
     * @return array{status: string, reason: string|null}
     */
    public function poll(StorageDestination $destination): array
    {
        $this->guardProvider($destination);

        $deviceCode = Cache::get($this->cacheKey($destination));

        if (! is_string($deviceCode) || $deviceCode === '') {
            // Nothing in flight. Either nobody started, or the code outlived
            // its half hour — both mean "press Connect again", and neither is
            // a failure of Google's.
            return ['status' => 'expired', 'reason' => 'storage.oauth.code_expired'];
        }

        $result = $this->flow->poll(
            (string) $destination->configValue('client_id', ''),
            (string) $destination->configValue('client_secret', ''),
            $deviceCode,
        );

        if ($result['status'] !== 'approved') {
            // A dead code is cleared so the next poll says "press Connect"
            // rather than re-asking Google about something it has forgotten.
            if (in_array($result['status'], ['denied', 'expired', 'failed'], true)) {
                Cache::forget($this->cacheKey($destination));
            }

            return ['status' => $result['status'], 'reason' => $result['reason']];
        }

        return $this->store($destination, (string) $result['refresh_token']);
    }

    /**
     * @return array{status: string, reason: string|null}
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
            Cache::forget($this->cacheKey($destination));

            return ['status' => 'failed', 'reason' => (string) $prepared['reason']];
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

        Cache::forget($this->cacheKey($destination));

        $this->activityLogger->log('storage_destination.connected', $destination, [
            'name' => $destination->name,
            // The address, never the token. This row is readable by anyone who
            // can see the activity log.
            'account_email' => $prepared['account_email'],
        ]);

        return ['status' => 'approved', 'reason' => null];
    }

    /**
     * A name the operator will recognise in their own Drive, months later,
     * next to their holiday photos.
     */
    private function folderName(StorageDestination $destination): string
    {
        return trim((string) config('app.name', 'Panel')).' backups — '.$destination->name;
    }

    private function cacheKey(StorageDestination $destination): string
    {
        return "storage:oauth:device:{$destination->id}";
    }

    private function guardProvider(StorageDestination $destination): void
    {
        if ($destination->provider !== StorageProvider::GoogleDriveOauth) {
            $this->fail('storage.oauth.wrong_provider');
        }
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['provider' => [__($key)]]);
    }
}
