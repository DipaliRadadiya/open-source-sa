<?php

namespace App\Exceptions\Server\Database;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remote access was asked for on a PostgreSQL cluster that is only listening on
 * loopback, and widening that takes a **restart**.
 *
 * `listen_addresses` is not reloadable — PostgreSQL's documentation is explicit
 * that it "can only be set at server start" — so there is no version of this
 * that quietly works. Every application connected to the cluster loses its
 * connections for the moment it takes.
 *
 * So the request stops here and asks. **409, not 422**: nothing about the
 * request is malformed and the caller is perfectly entitled to make it — the
 * server is simply in a state that has to change first, which is what 409
 * describes. A 422 would tell the client it sent something wrong, and it did
 * not.
 *
 * The client re-sends with `restart_cluster: true`. That second request is the
 * consent, and it is deliberately a separate round trip rather than a flag
 * somebody could set once and forget: restarting a database is exactly the kind
 * of thing that should be hard to do by accident.
 */
class RemoteAccessRestartRequiredException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('errors/database.remote_access_restart_required'),
            // Stable, so the client can recognise this one case and show a
            // confirm dialog rather than matching on translated prose.
            'code' => 'restart_required',
        ], 409);
    }
}
