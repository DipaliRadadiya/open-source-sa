<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\StorageDestination\ConnectGoogleDrive;
use App\Actions\Server\StorageDestination\CreateStorageDestination;
use App\Actions\Server\StorageDestination\DeleteStorageDestination;
use App\Actions\Server\StorageDestination\UpdateStorageDestination;
use App\Enums\StorageProvider;
use App\Exceptions\Server\Storage\LocalBackupFolderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\StorageDestination\OauthCallbackRequest;
use App\Http\Requests\Server\StorageDestination\StoreStorageDestinationRequest;
use App\Http\Requests\Server\StorageDestination\UpdateStorageDestinationRequest;
use App\Http\Resources\StorageDestinationResource;
use App\Models\StorageDestination;
use App\Services\Server\Backups\Storage\GoogleOauthRedirect;
use App\Services\Server\Backups\Storage\LocalBackupFolder;
use App\Services\Server\Backups\Storage\StorageConnectionProber;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StorageDestinationController extends Controller
{
    /**
     * Hand back the Google consent URL to send the operator to. No 302: the frontend's fetch
     * layer would follow it and try to parse Google's sign-in page as JSON.
     */
    public function oauthStart(StorageDestination $storageDestination, ConnectGoogleDrive $action): JsonResponse
    {
        return response()->json(['oauth' => $action->start($storageDestination)]);
    }

    /**
     * Finish the round trip Google sent back to the callback page.
     *
     * No destination in the route: it is sealed inside `state`, because the forwarding page's
     * query parameters could be written by anybody. See `GoogleOauthState`.
     */
    public function oauthCallback(OauthCallbackRequest $request, ConnectGoogleDrive $action): JsonResponse
    {
        $result = $action->complete(
            (string) $request->validated('code'),
            (string) $request->validated('state'),
        );

        return response()->json([
            'oauth' => [
                'status' => $result['status'],
                // Built on read in the viewer's locale; the action returns a code, never prose.
                'message' => $result['reason'] ? __($result['reason']) : null,
            ],
            // Null when `state` never resolved to a destination.
            'storage_destination' => $result['destination']
                ? StorageDestinationResource::make($result['destination']->fresh())->resolve()
                : null,
        ]);
    }

    public function index(GoogleOauthRedirect $redirect, LocalBackupFolder $local): JsonResponse
    {
        $destinations = ListSort::caseInsensitive(StorageDestination::query(), 'name')->get();

        return response()->json([
            'storage_destinations' => StorageDestinationResource::collection($destinations)->resolve(),

            // Panel-wide, not per destination: it is needed before any destination exists, when
            // creating the Google OAuth client.
            'google_oauth_redirect_uri' => $redirect->redirectUri(),

            // Where a "this server" destination may live; the form reads the rule from here.
            'local' => [
                'allowed_roots' => $local->roots(),
                'default_path' => (string) config('server.backups.local.default_path', '/var/backups/panel'),
            ],
        ]);
    }

    public function store(StoreStorageDestinationRequest $request, CreateStorageDestination $action): JsonResponse
    {
        // This exact credential is already a destination: return it unchanged, not a second one.
        if (($existing = $request->existingRecord()) !== null) {
            return response()->json([
                'storage_destination' => StorageDestinationResource::make($existing)->resolve(),
                'existing' => true,
                'message' => __('storage.already_added', ['name' => $existing->name]),
            ]);
        }

        $data = $request->validated();

        // "This server": the folder is made before the row exists, so a failure leaves no
        // destination pointing at it.
        $folder = $data['provider'] === StorageProvider::Local->value
            ? $this->prepareLocalFolder((string) $data['config']['path'])
            : null;

        if ($folder !== null) {
            $data['config']['path'] = $folder['path'];
        }

        $destination = $action->execute($data);

        return response()->json([
            'storage_destination' => StorageDestinationResource::make($destination)->resolve(),
            'existing' => false,
            ...($folder !== null ? ['folder' => $folder] : []),
        ], 201);
    }

    /**
     * Create the folder, or take over an empty one, and say which.
     *
     * @return array{path: string, status: string, message: string}
     */
    private function prepareLocalFolder(string $path): array
    {
        $folders = app(LocalBackupFolder::class);
        $path = (string) $folders->normalise($path);

        try {
            $status = $folders->prepare($path);
        } catch (LocalBackupFolderException $e) {
            throw ValidationException::withMessages(['config.path' => [$e->userMessage()]]);
        }

        return [
            'path' => $path,
            'status' => $status,
            'message' => __('storage.local.folder_'.$status, ['path' => $path]),
        ];
    }

    public function show(StorageDestination $storageDestination): JsonResponse
    {
        return response()->json([
            'storage_destination' => StorageDestinationResource::make($storageDestination)->resolve(),
        ]);
    }

    public function update(
        UpdateStorageDestinationRequest $request,
        StorageDestination $storageDestination,
        UpdateStorageDestination $action,
    ): JsonResponse {
        $data = $request->validated();
        $folder = null;

        // A moved folder is prepared like a new one; backups in the old folder stay there.
        if ($storageDestination->provider === StorageProvider::Local && isset($data['config']['path'])) {
            $folder = $this->prepareLocalFolder((string) $data['config']['path']);
            $data['config']['path'] = $folder['path'];
        }

        $destination = $action->execute($storageDestination, $data);

        return response()->json([
            'storage_destination' => StorageDestinationResource::make($destination)->resolve(),
            ...($folder !== null ? ['folder' => $folder] : []),
        ]);
    }

    public function destroy(StorageDestination $storageDestination, DeleteStorageDestination $action): JsonResponse
    {
        $action->execute($storageDestination);

        return response()->json(null, 204);
    }

    /**
     * Probe an existing destination's credentials and reachability by uploading, reading and
     * deleting a sentinel object. Throttled: a slow endpoint must not tie up request workers.
     */
    public function test(
        StorageDestination $storageDestination,
        StorageConnectionProber $prober,
    ): JsonResponse {
        $result = $prober->probe($storageDestination);

        // Persist the verdict. Store the stable `error_class`, never `message` (prose in the
        // tester's locale).
        $storageDestination->forceFill([
            'last_tested_at' => now(),
            'last_test_success' => $result['success'],
            'last_test_error' => $result['error_class'],
        ])->save();

        return response()->json([
            // Not the resource shape: that describes stored config, this is a live probe.
            'test' => [
                'success' => $result['success'],
                'latency_ms' => $result['latency_ms'],
                'message' => $result['message'],
                'error_class' => $result['error_class'],
                // Local timestamp for the UI's "Tested 3s ago".
                'tested_at' => now()->format('d-m-Y H:i:s'),
            ],
            // Always 200: the probe ran. A 5xx would make the frontend treat a good answer as a
            // server fault. `test.success` carries the verdict.
        ]);
    }
}
