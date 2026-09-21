<?php

namespace App\Exceptions;

use App\Services\Server\Backups\Storage\Drivers\ClassifiesFailures;
use RuntimeException;

/**
 * An upload that stopped transferring and was abandoned.
 *
 * Its own type rather than a message match in the runner, for the reason
 * {@see ClassifiesFailures}
 * documents at length: Flysystem wraps every adapter failure, so by the time
 * one reaches the runner the string that identifies it is buried inside two
 * other strings, and a rule that greps for it there matches the wrapper as
 * readily as the cause. The step knows exactly what happened; saying so in the
 * type means nothing downstream has to guess.
 *
 * The distinction is worth a class because the two failures lead opposite ways.
 * An ordinary upload error means something is wrong with the destination — bad
 * credentials, a full Drive, a missing folder — and the operator should go and
 * look at it. A stall means the connection was fine, the transfer started, and
 * then the far end went quiet: nothing to fix, and the next scheduled run will
 * very likely succeed. Reporting the second as the first sends people to audit
 * a configuration that was never broken.
 */
class UploadStalled extends RuntimeException
{
    public const REASON = 'upload_stalled';
}
