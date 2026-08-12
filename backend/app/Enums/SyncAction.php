<?php

namespace App\Enums;

/**
 * What became of one discovered thing.
 *
 * `Skipped` and `Failed` are recorded rather than omitted. A sync that lists
 * only its successes is indistinguishable from one that silently missed half
 * the server, and telling those apart is the point of the feature.
 */
enum SyncAction: string
{
    /** Seen on the server, nothing written — every preview item. */
    case Found = 'found';

    /** A panel row now exists for it. */
    case Adopted = 'adopted';

    /** Deliberately not adopted; `reason` says why. */
    case Skipped = 'skipped';

    /** Discovery or adoption errored; `reason` says how. */
    case Failed = 'failed';
}
