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

    /**
     * Live token health, one row per connected account.
     *
     * Deliberately separate from index(): index is a cheap database read that
     * other screens (the app-create wizard) also use, and it must not inherit
     * a third party's latency. The page loads both in parallel and fills the
     * badges in when this answers.
     *
     * Nothing here is cached or stored — a token can be revoked at the
     * provider at any moment, so a persisted verdict would lie.
     */
    public function status(GitProviderManager $manager): JsonResponse
    {
        $statuses = GitAccount::query()->orderBy('label')->get()
            ->map(function (GitAccount $account) use ($manager) {
                $result = $manager->driver($account->provider)->status($account);
                $expiresAt = $result['expires_at'];

                return [
                    'id' => $account->id,
                    'label' => $account->label,
                    'provider' => $account->provider,
                    'provider_title' => __("git.providers.{$account->provider}"),
                    'status' => $result['status'],
                    'status_title' => __("git.status.{$result['status']}"),
                    'expires_at' => $expiresAt?->format('d-m-Y H:i:s'),
                    'expires_in_days' => $expiresAt ? now()->startOfDay()->diffInDays($expiresAt->startOfDay(), false) : null,
                    'checked_at' => now()->format('d-m-Y H:i:s'),
                ];
            })
            ->all();

        return response()->json(['statuses' => $statuses]);
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
