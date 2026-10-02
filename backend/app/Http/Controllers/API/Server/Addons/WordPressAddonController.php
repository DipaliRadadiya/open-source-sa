<?php

namespace App\Http\Controllers\API\Server\Addons;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Addons\WordPressAddonRequest;
use App\Http\Resources\AddonRunResource;
use App\Jobs\RunAddonCommand;
use App\Models\AddonRun;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Addons\WpToolkit;
use App\Services\Addons\WpToolkitCommands;
use Illuminate\Http\JsonResponse;

/**
 * Every wp-toolkit command, for Central. Which one is the route's `command`
 * default; what it takes and runs is its entry in WpToolkitCommands.
 *
 * A synchronous command answers with the toolkit's own JSON, unchanged. A
 * queued one answers 202 with an AddonRun to poll at /central/addons/runs/{id}.
 */
class WordPressAddonController extends Controller
{
    public function __invoke(WordPressAddonRequest $request, Application $application, WpToolkit $toolkit, ActivityLogger $log): JsonResponse
    {
        // A site-type check, not a permission: the toolkit only makes sense
        // against a WordPress install, and a 404 is what the panel answers for
        // every feature a site's type does not have.
        abort_unless($application->site_type === 'wordpress' && $application->status === ApplicationStatus::Active, 404);

        $command = $request->command();
        $entry = WpToolkitCommands::get($command);

        $arguments = $this->arguments($entry, $request, $application, $toolkit);

        if ($entry['mutates']) {
            $log->log('application.addon_command', $application, [
                'domain' => $application->domain,
                'addon' => $toolkit->label(),
                'command' => $command,
            ]);
        }

        if ($entry['async']) {
            $run = AddonRun::create([
                'application_id' => $application->id,
                'addon' => $toolkit->name(),
                'command' => $command,
                'status' => AddonRun::QUEUED,
                'arguments' => $arguments,
            ]);

            RunAddonCommand::dispatch($run->id);

            return AddonRunResource::make($run)->response()->setStatusCode(202);
        }

        return response()->json($toolkit->run($arguments, (int) config('server.addons.sync_timeout'), ['application' => $application->id]));
    }

    /**
     * The command's own words, with the site target spliced in ahead of any
     * `--` so it is read as flags, never as values.
     *
     * @return array<int, string>
     */
    private function arguments(array $entry, WordPressAddonRequest $request, Application $application, WpToolkit $toolkit): array
    {
        $words = ($entry['argv'])($request->validated(), $this->routeParameters($request));
        $target = ($entry['rules_target'] ?? false) ? $toolkit->rulesTarget($application) : $toolkit->target($application);

        $separator = array_search('--', $words, true);

        if ($separator === false) {
            return [...$words, ...$target];
        }

        return [...array_slice($words, 0, $separator), ...$target, ...array_slice($words, $separator)];
    }

    /** @return array<string, string> */
    private function routeParameters(WordPressAddonRequest $request): array
    {
        return array_map('strval', array_filter(
            $request->route()->parameters(),
            fn ($value, $key) => in_array($key, ['slug', 'part', 'rule'], true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
