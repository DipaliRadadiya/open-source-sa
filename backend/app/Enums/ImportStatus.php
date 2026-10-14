<?php

namespace App\Enums;

/**
 * Where a database import is. `uploading` comes first because the file arrives
 * in chunks over many requests, and the row has to exist from the first one.
 */
enum ImportStatus: string
{
    /** Chunks are arriving; nothing has touched the database. */
    case Uploading = 'uploading';

    /** The file is complete and the user confirmed; waiting for a worker. */
    case Queued = 'queued';

    /** Backing up, importing, or putting the backup back — see `step`. */
    case Running = 'running';

    /** The database now holds the file's contents. */
    case Completed = 'completed';

    /** Refused or failed; `reason` says why, `restored` whether the backup went back. */
    case Failed = 'failed';
}
