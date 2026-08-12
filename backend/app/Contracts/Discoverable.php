<?php

namespace App\Contracts;

use App\Models\SyncRun;

/**
 * One kind of thing the panel can find on the server and take a record of.
 *
 * Deliberately not one generic sync: "what exists" is genuinely different per
 * resource. A database is a name from `SHOW DATABASES`; a site is a vhost, a
 * directory, an owner and a type inferred from files on disk. What they share
 * is the shape of the answer, not the question.
 *
 * Implementations must obey two rules the whole feature rests on:
 *
 *  - `discover()` never writes to the server. Not a file, not a service, not
 *    a reload. If people are afraid to press Sync, the feature is worthless.
 *  - `adopt()` writes only to the panel's own database, and only rows that
 *    are marked as not-managed, so nothing the user hand-wrote is at risk of
 *    being rewritten until they ask for that separately.
 */
interface Discoverable
{
    /** Stable identifier used in `sync_items.resource_type` and the API. */
    public function resourceType(): string;

    /**
     * Resource types that must have run before this one.
     *
     * Real dependencies, not preferences: an ssh key belongs to a user, a
     * worker to an application. Running out of order means inventing parents.
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
