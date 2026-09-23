<?php

namespace App\Enums;

/**
 * Where a file-manager archive operation is, from the screen's point of view.
 *
 * A completed row is kept rather than deleted, but only briefly — unlike a
 * database export, the *artefact* here lands in the user's own file tree and
 * is visible in the browser they started from. The row exists to answer "is
 * something happening" and "why did it fail", neither of which the file on
 * disk can answer, and both of which stop mattering once the user has seen
 * the result.
 */
enum FileArchiveStatus: string
{
    /** Accepted and validated, waiting for a worker. */
    case Queued = 'queued';

    /** tar/zip/unzip is running right now. */
    case Running = 'running';

    /** The archive (or the extracted tree) is on disk. */
    case Completed = 'completed';

    /** It failed; `reason` says how. */
    case Failed = 'failed';
}
