<?php

namespace App\Http\Controllers\API\Server\Addons;

use App\Enums\ApplicationStatus;
use App\Exceptions\Addons\AddonException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Addons\ObjectCacheProRequest;
use App\Http\Resources\AddonRunResource;
use App\Jobs\RunAddonCommand;
use App\Models\AddonRun;
use App\Models\Application;
use App\Models\ApplicationRedisAccount;
use App\Services\ActivityLogger;
use App\Services\Addons\SiteRedisAccount;
use App\Services\Addons\WpToolkit;
use Illuminate\Http\JsonResponse;

/**
 * Object Cache Pro for Central: the v7 agent's object-cache-pro routes.
 *
 * The panel owns the Redis side -- each site gets its own Redis user, limited
 * to its own keys (SiteRedisAccount) -- and the WP Toolkit owns the WordPress
 * side. The credentials reach the toolkit on stdin, never on a command line.
 */
class ObjectCacheProController extends Controller
{
    public function __construct(private WpToolkit $toolkit, private SiteRedisAccount $redis, private ActivityLogger $log) {}

    public function show(Application $application): JsonResponse
    {
        $this->wordpress($application);

        return response()->json($this->toolkit->run(
            $this->words($application, 'status'),
            (int) config('server.addons.sync_timeout'),
            ['application' => $application->id],
        ));
    }

    /** Create the site's Redis account, then install and configure (queued: it downloads). */
    public function store(ObjectCacheProRequest $request, Application $application): JsonResponse
    {
        $this->wordpress($application);

        $account = $this->redis->ensure($application);
        $account->settings = $request->validated();
        $account->save();

        $run = $this->queue($application, 'object-cache-pro.enable', $this->words($application, 'enable'), $account, true);
        $this->log('object-cache-pro.enable', $application);

        return AddonRunResource::make($run)->response()->setStatusCode(202);
    }

    /**
     * A new Redis password for the site, written into its settings, with no
     * moment where the site cannot log in (SiteRedisAccount::rotate). Replaces
     * v7's "update", which took a password from the caller: the password is
     * the panel's to choose now.
     */
    public function rotate(Application $application): JsonResponse
    {
        $this->wordpress($application);

        $account = ApplicationRedisAccount::where('application_id', $application->id)->first()
            ?? throw AddonException::of('addon_command_failed', $this->toolkit->label(), __('errors/addons.object_cache_not_enabled'));

        $answer = $this->redis->rotate($account, fn (ApplicationRedisAccount $rotated) => $this->toolkit->run(
            $this->words($application, 'configure'),
            (int) config('server.addons.sync_timeout'),
            ['application' => $application->id],
            $this->input($rotated, false),
        ));
        $this->log('object-cache-pro.rotate', $application);

        return response()->json($answer);
    }

    /** Remove the plugin and its settings, then the Redis account. */
    public function destroy(Application $application): JsonResponse
    {
        $this->wordpress($application);

        $answer = $this->toolkit->run(
            $this->words($application, 'disable'),
            (int) config('server.addons.sync_timeout'),
            ['application' => $application->id],
        );

        // Only once WordPress no longer uses it: removed first, the site would
        // be left trying to log in to an account that is gone.
        $this->redis->remove($application);
        $this->log('object-cache-pro.disable', $application);

        return response()->json($answer);
    }

    private function queue(Application $application, string $command, array $words, ApplicationRedisAccount $account, bool $withUrl): AddonRun
    {
        $run = AddonRun::create([
            'application_id' => $application->id,
            'addon' => $this->toolkit->name(),
            'command' => $command,
            'status' => AddonRun::QUEUED,
            'arguments' => $words,
            'input' => $this->input($account, $withUrl),
        ]);

        RunAddonCommand::dispatch($run->id);

        return $run;
    }

    /**
     * `wp-toolkit object-cache-pro <verb>` for this site. (Not the binary: the
     * toolkit is the binary, these are its arguments.)
     *
     * @return array<int, string>
     */
    private function words(Application $application, string $verb): array
    {
        return ['object-cache-pro', $verb, ...$this->toolkit->target($application)];
    }

    /** The toolkit's stdin: the connection plus what Central sent. */
    private function input(ApplicationRedisAccount $account, bool $withUrl): string
    {
        $settings = (array) $account->settings;
        if (! $withUrl) {
            unset($settings['plugin_url']);
        }

        return json_encode([...$settings, ...$this->redis->connection($account)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function wordpress(Application $application): void
    {
        abort_unless($application->site_type === 'wordpress' && $application->status === ApplicationStatus::Active, 404);
    }

    private function log(string $command, Application $application): void
    {
        $this->log->log('application.addon_command', $application, [
            'domain' => $application->domain, 'addon' => $this->toolkit->label(), 'command' => $command,
        ]);
    }
}
