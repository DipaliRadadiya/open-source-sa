<?php

namespace App\Contracts;

/**
 * An engine whose listening address is decided at start-up, so the first
 * remote user needs it widened and the engine restarted.
 *
 * PostgreSQL (`listen_addresses`) and MySQL/MariaDB (`bind-address`), both
 * loopback-only as their Ubuntu packages ship them. MongoDB is not here: its
 * installer binds it off-box from the start.
 */
interface ListensRemotely
{
    /** Is the engine already reachable from off the box? */
    public function listensRemotely(): bool;

    /** Bind every interface, then restart — the only way the setting takes. */
    public function openRemoteListening(): void;

    /** Is the widening the panel's own (its drop-in or ALTER SYSTEM), so the panel may undo it? */
    public function remoteListeningOpenedByPanel(): bool;

    /** Undo openRemoteListening(), then restart; on a failed restart, put it back. */
    public function closeRemoteListening(): void;
}
