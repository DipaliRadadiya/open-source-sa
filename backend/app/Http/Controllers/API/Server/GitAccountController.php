<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Git\ConnectGitAccount;
use App\Actions\Server\Git\DisconnectGitAccount;
use App\Actions\Server\Git\UpdateGitAccount;
use App\Actions\Server\Git\VerifyGitAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Git\StoreGitAccountRequest;
use App\Http\Requests\Server\Git\UpdateGitAccountRequest;
use App\Http\Resources\GitAccountResource;
use App\Models\GitAccount;
use App\Services\Git\GitProviderManager;
use Illuminate\Http\JsonResponse;

class GitAccountController extends Controller
{
    /**
     * Supported providers + the fields each connect form needs. Data-driven
     * so the frontend never hardcodes a per-provider form.
     */
    public function providers(GitProviderManager $manager): JsonResponse
    {
        return response()->json(['providers' => $manager->catalog()]);
    }

    public function index(): JsonResponse
    {
        $accounts = GitAccount::query()->orderBy('label')->get();

        return response()->json([
            'git_accounts' => GitAccountResource::collection($accounts)->resolve(),
        ]);
    }

    public function store(StoreGitAccountRequest $request, ConnectGitAccount $action): JsonResponse
    {
        $account = $action->execute($request->validated());

        return response()->json([
            'git_account' => GitAccountResource::make($account)->resolve(),
        ], 201);
    }

    public function update(GitAccount $account, UpdateGitAccountRequest $request, UpdateGitAccount $action): JsonResponse
    {
        $account = $action->execute($account, $request->validated());

        return response()->json([
            'git_account' => GitAccountResource::make($account)->resolve(),
        ]);
    }

    public function test(GitAccount $account, VerifyGitAccount $action): JsonResponse
    {
        $account = $action->execute($account);

        return response()->json([
            'git_account' => GitAccountResource::make($account)->resolve(),
        ]);
    }

    public function destroy(GitAccount $account, DisconnectGitAccount $action): JsonResponse
    {
        $action->execute($account);

        return response()->json(['deleted' => true]);
    }
}
