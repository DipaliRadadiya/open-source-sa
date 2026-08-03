<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\AddDomain;
use App\Actions\Server\Application\ChangePrimaryDomain;
use App\Actions\Server\Application\RemoveDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\StoreApplicationDomainRequest;
use App\Http\Resources\ApplicationDomainResource;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Services\Server\Applications\DnsVerifier;
use Illuminate\Http\JsonResponse;

class ApplicationDomainController extends Controller
{
    public function index(Application $application): JsonResponse
    {
        return response()->json([
            'domains' => ApplicationDomainResource::collection(
                $application->domains()->orderByRaw("type = 'primary' desc")->orderBy('domain')->get()
            )->resolve(),
        ]);
    }

    public function store(Application $application, StoreApplicationDomainRequest $request, AddDomain $action): JsonResponse
    {
        return response()->json([
            'domain' => ApplicationDomainResource::make(
                $action->execute($application, $request->validated())
            )->resolve(),
        ], 201);
    }

    /**
     * Re-check DNS for one name.
     *
     * Its own endpoint because DNS propagation is something the user waits on:
     * they add a record at their registrar and come back. Re-checking has to
     * be a button, not a side effect of adding the domain again.
     */
    public function verify(Application $application, ApplicationDomain $domain, DnsVerifier $dns): JsonResponse
    {
        abort_unless($domain->application_id === $application->id, 404);

        return response()->json([
            'domain' => ApplicationDomainResource::make($dns->verify($domain))->resolve(),
        ]);
    }

    /**
     * Promote a name to primary — its own endpoint, not a field update. The
     * vhost file and both log files are named after the primary domain, so
     * this renames three files and has to remove the old configuration.
     */
    public function makePrimary(Application $application, ApplicationDomain $domain, ChangePrimaryDomain $action): JsonResponse
    {
        abort_unless($domain->application_id === $application->id, 404);

        $action->execute($application, $domain);

        return response()->json([
            'domains' => ApplicationDomainResource::collection(
                $application->fresh()->domains()->orderByRaw("type = 'primary' desc")->orderBy('domain')->get()
            )->resolve(),
        ]);
    }

    public function destroy(Application $application, ApplicationDomain $domain, RemoveDomain $action): JsonResponse
    {
        abort_unless($domain->application_id === $application->id, 404);

        $action->execute($domain);

        return response()->json(null, 204);
    }
}
