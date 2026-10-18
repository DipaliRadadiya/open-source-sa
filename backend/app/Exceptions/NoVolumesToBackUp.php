<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A `volumes` backup of a site that has no volumes (S-02).
 *
 * Its own type, like {@see UploadStalled}, so the runner records why rather
 * than the step name. Without it the run failed one step later, at
 * `archive_files` — tar refusing to create an empty archive — and the row said
 * "usually the server is out of disk space", which sent people to a disk that
 * was fine. The save and the manual run refuse this case up front; this is the
 * half that catches a target saved before they did, on its schedule.
 */
class NoVolumesToBackUp extends RuntimeException
{
    public const REASON = 'target_no_volumes';
}
