<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\SystemUser\DeploySshKey;
use App\Actions\Server\SystemUser\RemoveSshKey;
use App\Exceptions\Server\ServerOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\SshKey\DeploySshKeyRequest;
use App\Http\Requests\Server\SshKey\RemoveSshKeysRequest;
use App\Http\Resources\SshKeyResource;
use App\Models\SshKey;
use App\Models\SystemUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SSH keys across every system user on this server — for central's key list
 * (2026-10-28), where a key is known by its fingerprint, not by this panel's
 * row id. Per-user screens keep using /system-users/{id}/ssh-keys.
 *
 * Only keys added through the panel. Keys put into authorized_keys by hand,
 * and root's or the provider's keys, are never listed or touched here.
 */
class SshKeysController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = SshKey::query()
            ->with('systemUser:id,username')
            ->when($request->filled('fingerprint'), fn ($q) => $q->where('fingerprint', (string) $request->query('fingerprint')))
            ->when($request->filled('system_user_id'), fn ($q) => $q->where('system_user_id', (int) $request->query('system_user_id')))
            ->orderBy('system_user_id')
            ->latest()
            ->get();

        return response()->json(['ssh_keys' => SshKeyResource::collection($keys)->resolve()]);
    }

    public function deploy(DeploySshKeyRequest $request, DeploySshKey $action): JsonResponse
    {
        $data = $request->validated();
        $users = SystemUser::query()->whereIn('id', $data['system_user_ids'])->orderBy('id')->get();

        return response()->json($action->execute(
            ['name' => $data['name'], 'public_key' => $data['public_key']],
            $users,
        ));
    }

    /**
     * Idempotent: a fingerprint no user holds removes nothing and is not an
     * error, so "remove this key everywhere" can be sent to every server.
     */
    public function destroy(RemoveSshKeysRequest $request, RemoveSshKey $action): JsonResponse
    {
        $data = $request->validated();
        $keys = SshKey::query()
            ->with('systemUser')
            ->where('fingerprint', $data['fingerprint'])
            ->when(isset($data['system_user_ids']), fn ($q) => $q->whereIn('system_user_id', $data['system_user_ids']))
            ->get();

        $results = [];

        foreach ($keys as $key) {
            $row = ['system_user' => ['id' => $key->systemUser->id, 'username' => $key->systemUser->username]];

            try {
                $action->execute($key->systemUser, $key);
                $results[] = $row + ['status' => 'removed'];
            } catch (ServerOperationException $e) {
                $results[] = $row + ['status' => 'failed', 'reference' => $e->reference];
            }
        }

        return response()->json(['results' => $results]);
    }
}
