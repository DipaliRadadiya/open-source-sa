<?php

namespace App\Actions\Server\Database;

use App\Contracts\ListensRemotely;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\RemoteAccessState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Stop an engine listening off the box once no remote user needs it. Restarts the engine, so it
 * runs only when asked; refused while a remote user exists or when the server's own setup (not
 * the panel) opened it.
 */
class CloseRemoteAccess
{
    public function __construct(
        private DatabaseManager $manager,
        private RemoteAccessState $state,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array<string, mixed> the state after closing
     */
    public function execute(string $engine): array
    {
        $label = (string) config("server.databases.engines.{$engine}.label", $engine);
        $lock = Cache::lock("database:remote-access:{$engine}", 180);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['engine' => [__('errors/database.remote_access_close_busy')]]);
        }

        try {
            $state = $this->state->of($engine);

            $users = $this->state->remoteUsers($engine);
            if ($users->isNotEmpty()) {
                throw ValidationException::withMessages(['engine' => [__('errors/database.remote_access_close_users_left', [
                    'engine' => $label,
                    'users' => $users->pluck('username')->unique()->take(10)->implode(', '),
                ])]]);
            }

            if ($state['listening_remotely'] !== true) {
                throw ValidationException::withMessages(['engine' => [__('errors/database.remote_access_already_closed', ['engine' => $label])]]);
            }

            if ($state['opened_by_panel'] !== true) {
                throw ValidationException::withMessages(['engine' => [__('errors/database.remote_access_not_ours', ['engine' => $label])]]);
            }

            $driver = $this->manager->engine($engine);
            assert($driver instanceof ListensRemotely);
            $driver->closeRemoteListening();

            $this->activityLogger->log('database.remote_access_closed', null, ['engine' => $label]);

            return $this->state->of($engine);
        } finally {
            $lock->release();
        }
    }
}
