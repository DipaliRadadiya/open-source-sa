<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Git\ListBranchesRequest;
use App\Http\Requests\Server\Git\ListRepositoriesRequest;
use App\Models\GitAccount;
use App\Services\Git\GitProviderManager;
use Illuminate\Http\JsonResponse;

/**
 * Browsing a connected account. Read-only, and what comes back is limited to
 * what the credential itself can reach — a repository-scoped Bitbucket token
 * legitimately lists a single repository.
 */
class GitRepositoryController extends Controller
{
    public function repositories(GitAccount $account, ListRepositoriesRequest $request, GitProviderManager $manager): JsonResponse
    {
        $result = $manager->driver($account->provider)->repositories(
            $account,
            $request->string('search')->toString() ?: null,
            (int) $request->integer('page', 1),
        );

        return response()->json([
            'repositories' => $result['repositories'],
            'meta' => ['page' => $result['page'], 'has_more' => $result['has_more']],
        ]);
    }

    public function branches(GitAccount $account, ListBranchesRequest $request, GitProviderManager $manager): JsonResponse
    {
        $branches = $manager->driver($account->provider)->branches(
            $account,
            $request->string('repository')->toString(),
        );

        return response()->json(['branches' => $branches]);
    }
}
