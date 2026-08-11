<?php

namespace App\Http\Controllers\API\Server;

use App\Enums\RestoreStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Backup\RestoreBackupRequest;
use App\Http\Requests\Server\Restore\IndexRestoresRequest;
use App\Http\Resources\RestoreResource;
use App\Jobs\RunRestore;
use App\Models\Backup;
use App\Models\Restore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Putting an application back to a backup.
 *
 * The only destructive operation in the panel, so it is deliberately awkward:
 * the caller needs `backup` at manage level, must type the application's
 * domain, and cannot aim a backup at any application other than the one it
 * came from.
 */
class RestoreController extends Controller
{
    /**
     * Start a restore.
     *
     * 202 with the row: unlike a backup, the record exists before the worker
     * picks it up, because a restore that never starts still needs to be
     * visible — it is the one operation where "nothing happened" and "it is
     * about to overwrite my site" must not look the same.
     */
    public function store(RestoreBackupRequest $request, Backup $backup): JsonResponse
    {
        $restore = Restore::create([
            'backup_id' => $backup->id,
            // Taken from the backup, never from the request. A restore aimed
            // at a different application would write one customer's database
            // over another's — that is what the clone feature is for.
            'application_id' => $backup->application_id,
            'user_id' => $request->user()?->id,
            'type' => $request->requestedType(),
            'status' => RestoreStatus::Pending,
            'reference' => (string) Str::uuid(),
        ]);

        RunRestore::dispatch($restore->id, $backup->application_id);

        return response()->json([
            'restore' => RestoreResource::make($restore)->resolve(),
        ], 202);
    }

    /** Poll one restore while it runs. */
    public function show(Restore $restore): JsonResponse
    {
        return response()->json([
            'restore' => RestoreResource::make($restore)->resolve(),
        ]);
    }

    /** History for one application — what was restored, when, and by whom. */
    public function index(IndexRestoresRequest $request): JsonResponse
    {
        $filter = $request->validated('filter', []);

        $restores = Restore::query()
            ->with('application:id,name,domain')
            ->when($filter['application_id'] ?? null, fn ($q, $id) => $q->where('application_id', $id))
            ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filter['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filter['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filter['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate($request->validated('per_page', 20));

        return response()->json([
            'restores' => RestoreResource::collection($restores)->resolve(),
            'meta' => [
                'current_page' => $restores->currentPage(),
                'per_page' => $restores->perPage(),
                'total' => $restores->total(),
                'last_page' => $restores->lastPage(),
            ],
        ]);
    }
}
