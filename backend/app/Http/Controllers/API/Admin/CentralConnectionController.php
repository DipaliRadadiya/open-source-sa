<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CentralConnectionResource;
use App\Models\CentralConnection;
use App\Services\ActivityLogger;
use App\Services\Central\CentralUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Connecting this panel to the vendor's central panel.
 *
 * The whole feature is one key: the admin turns it on, copies the token once,
 * and pastes it into the central panel, which then calls this panel's API with
 * it. The key carries the same access an administrator has — there is nothing
 * to configure, and nothing partial to reason about.
 *
 * The consent story is the point: nothing is shared until an administrator
 * explicitly connects, the row records who did it and when, and disconnecting
 * deletes the token so access stops on the next request.
 */
class CentralConnectionController extends Controller
{
    public function show(): JsonResponse
    {
        // Falls back to the most recent connection so a disconnected panel can
        // still show when it was last connected, rather than pretending it
        // never was.
        $connection = CentralConnection::active()
            ?? CentralConnection::query()->latest('id')->first();

        return response()->json([
            'central' => $connection === null
                ? ['connected' => false]
                : CentralConnectionResource::make($connection->load('connectedBy'))->resolve(),
        ]);
    }

    /**
     * Issue the key. The plaintext token is in this response and nowhere else,
     * ever — only its hash is stored.
     */
    public function store(Request $request, CentralUser $centralUser, ActivityLogger $activityLogger): JsonResponse
    {
        if (CentralConnection::active() !== null) {
            // Deliberately not a silent re-issue: that would invalidate a
            // working integration on a stray click, and the person clicking
            // would have no idea why the central panel stopped answering.
            throw ValidationException::withMessages([
                'central' => [__('central.errors.already_connected')],
            ]);
        }

        $account = $centralUser->ensure();

        // No expiry. The admin asked for a key that keeps working; revocation
        // is the control, and an expired key silently breaking the integration
        // is the worse failure.
        $token = $account->createToken('central', ['*']);

        $connection = CentralConnection::create([
            'token_id' => $token->accessToken->getKey(),
            'connected_by_user_id' => $request->user()->id,
            'connected_at' => Carbon::now(),
        ]);

        $activityLogger->log('central.connected', $connection, [
            'username' => $request->user()->username,
        ]);

        return response()->json([
            'central' => CentralConnectionResource::make($connection->load('connectedBy'))->resolve(),
            // Shown once. There is no endpoint that returns it again.
            'token' => $token->plainTextToken,
        ], 201);
    }

    /**
     * Revoke. The token row is deleted rather than flagged, so the next
     * request carrying it fails at the guard — there is no window where a
     * revoked key still works.
     */
    public function destroy(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        $connection = CentralConnection::active();

        if ($connection === null) {
            throw ValidationException::withMessages([
                'central' => [__('central.errors.not_connected')],
            ]);
        }

        $connection->token()?->delete();

        $connection->update([
            'revoked_by_user_id' => $request->user()->id,
            'revoked_at' => Carbon::now(),
        ]);

        $activityLogger->log('central.disconnected', $connection, [
            'username' => $request->user()->username,
        ]);

        return response()->json(null, 204);
    }
}
