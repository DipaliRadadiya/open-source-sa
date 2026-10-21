<?php

namespace App\Exceptions\Server\Application;

/**
 * An adopted worker's program file no longer proves it is this worker's alone,
 * so it was neither stopped nor deleted.
 *
 * Server Sync records a `program` only for a file holding exactly one program,
 * named after it (`WorkerDiscoverer::adopt()`), because only such a file can be
 * removed without taking someone else's program with it. A file edited into a
 * shared one since then must not be deleted on the strength of that record.
 *
 * Renders as the worker-control failure it is to the Workers screen; deleting
 * the site catches it and reports the file as left in place instead.
 */
class UnprovenWorkerProgramException extends WorkerControlException
{
    public function __construct(string $reference, public readonly string $reason, public readonly string $path)
    {
        parent::__construct($reference);
    }
}
