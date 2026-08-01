<?php

namespace App\Enums;

/**
 * Where a database export is, from the screen's point of view.
 *
 * Unlike `InstallStatus`, a finished export **keeps** its row. An install can
 * delete its record because the filesystem then answers "is it installed?"
 * better than a table would; an export has no such authority to defer to — the
 * dump file on disk is the thing the row is describing, and someone has to
 * remember which database it came from and when.
 */
enum ExportStatus: string
{
    /** Accepted, waiting for a worker. */
    case Queued = 'queued';

    /** mysqldump (or mongodump) is running right now. */
    case Running = 'running';

    /** The file is on disk and downloadable. */
    case Completed = 'completed';

    /** The dump failed; `reason` says how. */
    case Failed = 'failed';
}
