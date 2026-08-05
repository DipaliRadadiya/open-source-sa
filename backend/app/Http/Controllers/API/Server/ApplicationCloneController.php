<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateClone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\CreateCloneRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

class ApplicationCloneController extends Controller
{
    public function store(CreateCloneRequest $request, Application $application, CreateClone $action): JsonResponse
    {
        $clone = $action->execute($application, $request->domain());

        return response()->json([
            'clone' => ApplicationResource::make($clone->load('systemUser'))->resolve(),
        ], 201);
    }
}
