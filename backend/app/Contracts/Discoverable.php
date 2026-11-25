<?php

namespace App\Contracts;

use App\Models\SyncRun;

/**
 * One kind of thing the panel can find on the server and take a record of.
 *
 * Not one generic sync: "what exists" differs per resource (a database is a name from
 * `SHOW DATABASES`; a site is a vhost, directory, owner and a type inferred from disk).
 *
 * Implementations must obey two rules:
 *
 *  - `discover()` never writes to the server (no file, service or reload), or nobody dares press
 *    Sync.
 *  - `adopt()` writes only to the panel's own database, and only rows marked not-managed, so
 *    nothing the user hand-wrote is rewritten until they ask.
 */
interface Discoverable
{
    /** Stable identifier used in `sync_items.resource_type` and the API. */
    public function resourceType(): string;

    /**
     * Resource types that must have run before this one.
     *
     * Real dependencies (an ssh key belongs to a user, a worker to an application); out of order
     * would invent parents.
     *
     * @return array<int, string>
     */
    public function dependsOn(): array;

    /**
     * Everything of this kind on the server that the panel does not already
     * track, read-only.
     *
     * @return array<int, array{key: string, label?: string, confidence?: int, evidence?: array<string, mixed>, skip?: string, attributes?: array<string, mixed>}>
     */
    public function discover(SyncRun $run): array;

    /**
     * Create the panel row for one discovered item.
     *
     * @param  array{key: string, attributes?: array<string, mixed>}  $item
     * @return object|null the created model, or null when nothing was created
     */
    public function adopt(array $item): ?object;
}
