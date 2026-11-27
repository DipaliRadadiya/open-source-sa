<?php

namespace App\Services\Server\Databases;

use App\Contracts\ListensRemotely;
use App\Models\DatabaseUser;
use Illuminate\Support\Collection;

/**
 * Whether an engine still accepts connections from off the box, and whether the panel may close
 * that: only when the panel opened it itself and no remote user is left.
 */
class RemoteAccessState
{
    public function __construct(private DatabaseManager $manager) {}

    public function supports(string $engine): bool
    {
        return in_array($engine, $this->manager->engineNames(), true)
            && $this->manager->engine($engine) instanceof ListensRemotely;
    }

    /**
     * @return array{engine: string, available: bool, listening_remotely: bool|null, opened_by_panel: bool|null, remote_users: int, can_close: bool}
     */
    public function of(string $engine): array
    {
        $driver = $this->manager->engine($engine);
        $remoteUsers = $this->remoteUsers($engine);
        $available = $driver->available();

        // A stopped engine answers nothing reliable, and "unreadable" reads as listening.
        $listening = $available && $driver instanceof ListensRemotely ? $driver->listensRemotely() : null;
        $ours = $available && $driver instanceof ListensRemotely ? $driver->remoteListeningOpenedByPanel() : null;

        return [
            'engine' => $engine,
            'available' => $available,
            'listening_remotely' => $listening,
            'opened_by_panel' => $ours,
            'remote_users' => $remoteUsers->count(),
            'can_close' => $listening === true && $ours === true && $remoteUsers->isEmpty(),
        ];
    }

    /**
     * @return Collection<int, DatabaseUser>
     */
    public function remoteUsers(string $engine)
    {
        return DatabaseUser::query()
            ->whereIn('connection_preference', ['remote', 'anywhere'])
            ->whereHas('database', fn ($query) => $query->where('engine', $engine))
            ->get(['id', 'database_id', 'username']);
    }
}
