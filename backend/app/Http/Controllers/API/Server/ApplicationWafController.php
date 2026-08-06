<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\UpdateApplicationWaf;
use App\Enums\WafCategory;
use App\Enums\WafMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateWafRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

class ApplicationWafController extends Controller
{
    /** The six categories and two modes, for the screen's own labels. */
    public function options(): JsonResponse
    {
        return response()->json([
            'waf_categories' => collect(WafCategory::cases())->map(fn (WafCategory $category) => [
                'value' => $category->value,
                'title' => $category->title(),
                // What it inspects, in one sentence. "Bad cookies" is not
                // enough to decide whether switching it off is safe, and
                // switching one off is what this screen is for.
                'description' => $category->description(),
            ]),
            'waf_modes' => collect(WafMode::cases())->map(fn (WafMode $mode) => [
                'value' => $mode->value,
                'title' => $mode->title(),
            ]),
        ]);
    }

    public function show(Application $application): JsonResponse
    {
        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser', 'wafRules']))->resolve(),
        ]);
    }

    public function update(UpdateWafRequest $request, Application $application, UpdateApplicationWaf $action): JsonResponse
    {
        $action->execute(
            $application,
            $request->enabled(),
            $request->mode(),
            $request->categories(),
            $request->exceptions(),
            $request->customRules(),
        );

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser', 'wafRules']))->resolve(),
        ]);
    }
}
