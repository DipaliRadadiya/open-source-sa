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
     * Hand back the Google consent URL to send the operator to.
     *
     * The panel does not redirect here — it returns the URL and the browser
     * navigates. An API that answered with a 302 would be followed by the
     * frontend's fetch layer, which would then try to parse Google's sign-in
     * page as JSON.
     */
    public function oauthStart(StorageDestination $storageDestination, ConnectGoogleDrive $action): JsonResponse
    {
        return response()->json(['oauth' => $action->start($storageDestination)]);
    }

    /**
     * Finish the round trip Google sent back to the callback page.
     *
     * Takes no destination in the route: which destination this was for is
     * sealed inside `state` and read from there, because the page forwarding
     * this holds query parameters anybody could have written. See
     * `GoogleOauthState`.
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
                // A finished sentence in the *viewer's* locale, built on read.
                // The action stores and returns a code, never prose.
                'message' => $result['reason'] ? __($result['reason']) : null,
            ],
            // Null when `state` never resolved to a destination — there is
            // genuinely nothing to show, and inventing an empty resource would
            // make the page render a connected-looking row for no row.
            'storage_destination' => $result['destination']
                ? StorageDestinationResource::make($result['destination']->fresh())->resolve()
                : null,
        ]);
    }

    public function index(GoogleOauthRedirect $redirect): JsonResponse
    {
        $destinations = ListSort::caseInsensitive(StorageDestination::query(), 'name')->get();

        return response()->json([
            'storage_destinations' => StorageDestinationResource::collection($destinations)->resolve(),

            // Panel-wide, and deliberately not hung off a destination. An
            // operator needs this string *before* any destination exists: it
            // goes into the Google OAuth client at creation time, which is
            // before they have a client id to paste into the form here. A
            // per-destination endpoint would only hand it over after the setup
            // step that needs it.
            'google_oauth_redirect_uri' => $redirect->redirectUri(),
        ]);
    }

    public function store(StoreStorageDestinationRequest $request, CreateStorageDestination $action): JsonResponse
    {
        // Exactly this credential is a destination already: that one, not a
        // second, and nothing about it changes — not its name, not its folder
        // (Dipali, 2026-10-28). The central panel uses the id either way.
        if (($existing = $request->existingRecord()) !== null) {
            return response()->json([
                'storage_destination' => StorageDestinationResource::make($existing)->resolve(),
                'existing' => true,
                'message' => __('storage.already_added', ['name' => $existing->name]),
            ]);
        }

        $data = $request->validated();

        // "This server" (#14): the folder is made before the row exists, so a
        // folder that cannot be made leaves no destination pointing at it.
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
     * Create the folder, or take over an empty one, and say which — "created"
     * and "found" are different news to the person who typed the path.
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

        // A moved folder is prepared like a new one. Backups already in the
        // old folder stay there; retention and restore of those read the
        // destination as it is now, as with every other provider.
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
     * Probe an existing destination's credentials and reachability by
     * uploading/reading/deleting a sentinel object. Heavy and external, so
     * throttled — a slow S3-compatible endpoint must not become a way to
     * occupy the panel's request workers.
     */
    public function test(
        StorageDestination $storageDestination,
        StorageConnectionProber $prober,
    ): JsonResponse {
        $result = $prober->probe($storageDestination);

        // Persist the verdict so it outlives the tab that asked for it. The
        // stable `error_class` category is stored, never `message` — that is
        // prose in the tester's locale, and a viewer in another language
        // would be shown someone else's language forever.
        $storageDestination->forceFill([
            'last_tested_at' => now(),
            'last_test_success' => $result['success'],
            'last_test_error' => $result['error_class'],
        ])->save();

        return response()->json([
            // Use a verb-named wrapper (`test`) rather than the resource
            // shape — the resource describes stored config, this result
            // describes a live network probe.
            'test' => [
                'success' => $result['success'],
                'latency_ms' => $result['latency_ms'],
                'message' => $result['message'],
                'error_class' => $result['error_class'],
                // Local timestamp so the UI can show "Tested 3s ago"
                // even after the page is closed.
                'tested_at' => now()->format('d-m-Y H:i:s'),
            ],
            // Always 200. The request itself succeeded — we asked the panel
            // to probe a destination and it did, then told us what it found.
            // A 5xx here would make the frontend's error interceptor treat a
            // perfectly good answer as a server fault, and the body is not
            // the `code`-bearing error envelope the rest of the API returns
            // for real failures. `test.success` carries the verdict.
        ]);
    }
}
