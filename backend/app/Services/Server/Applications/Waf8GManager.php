<?php

namespace App\Services\Server\Applications;

use App\Enums\WafMode;
use App\Exceptions\Server\Application\WafOperationException;
use App\Exceptions\Server\WebServer\WebServerReloadFailedException;
use App\Models\Application;
use App\Models\ApplicationWafRule;
use App\Services\ActivityLogger;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The 8G Firewall: six switchable categories plus per-app exceptions and custom rules. Same
 * apply → test → reload → rollback shape as Basic Auth and the AI Bot Blocker, but the ruleset
 * is declared once server-wide, not per vhost (see `ensureSharedMaps()`).
 *
 * Rules are rendered from an in-memory collection and saved only after the config test passes,
 * so a failed test leaves the database untouched.
 */
class Waf8GManager
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
        private V7Leftovers $leftovers,
        private ManagedFile $files,
        private ServerOps $serverOps,
    ) {}

    /**
     * @param  array<int, string>|null  $categories  null leaves the stored list alone
     * @param  array<int, string>|null  $exceptions  null leaves the stored list alone
     * @param  array<int, string>|null  $customRules  null leaves the stored list alone
     */
    public function apply(
        Application $application,
        bool $enabled,
        WafMode $mode,
        ?array $categories = null,
        ?array $exceptions = null,
        ?array $customRules = null,
    ): void {
        // Refused before anything is written: enabling where it cannot be enforced would show
        // protection that inspects nothing. Only enabling is blocked, so a site enabled earlier
        // can still be switched off or edited.
        if ($enabled && ! $this->webServers->driver()->supportsWaf()) {
            throw ValidationException::withMessages([
                'enabled' => __('errors/application.waf_unsupported', [
                    'server' => $this->webServers->driver()->name(),
                ]),
            ]);
        }

        $this->ensureSharedMaps();

        $application->loadMissing('wafRules');

        $previousEnabled = $application->waf_enabled;
        $previousMode = $application->waf_mode;
        $previousCategories = $application->waf_categories;

        // A partial update must not decide anything it did not mention (absent categories once
        // meant "all six on").
        $rulesChange = $exceptions !== null || $customRules !== null;
        $exceptions ??= $application->wafRules->where('type', 'exception')->pluck('value')->all();
        $customRules ??= $application->wafRules->where('type', 'block')->pluck('value')->all();

        $application->waf_enabled = $enabled;
        $application->waf_mode = $mode;
        $application->waf_categories = $categories ?? $application->waf_categories;

        // Rendered against the new rules before they reach the database (see class docblock).
        $application->setRelation('wafRules', $this->pendingRules($exceptions, $customRules));

        // v7's files for this setting go first: v8 never writes over a file it did not write.
        // Put back if the change does not hold.
        $moved = $this->leftovers->remove($application, 'firewall');

        $applied = $this->applyVhost($application);

        // Refused with v7's files gone: something may lean on them (an nginx conf.d map another
        // vhost reads), so they go back first and the change is kept if that is enough.
        $refused = $applied->failed() || $this->webServers->driver()->test()->failed();

        // Only the ones elsewhere: one at a name the panel writes would overwrite its own file.
        $elsewhere = $refused && $applied->ok ? $this->leftovers->outsideSiteConf($application, $moved) : [];

        if ($elsewhere !== []) {
            $this->leftovers->restore($application, $elsewhere);
            $moved = array_values(array_udiff($moved, $elsewhere, fn ($a, $b) => strcmp($a['from'], $b['from'])));
            $refused = $this->webServers->driver()->test()->failed();
        }

        if ($refused) {
            $this->leftovers->restore($application, $moved);
            $application->waf_enabled = $previousEnabled;
            $application->waf_mode = $previousMode;
            $application->waf_categories = $previousCategories;
            $application->load('wafRules');

            $restored = $this->applyVhost($application);

            throw new WafOperationException($applied->failed() ? $applied->reference : $restored->reference);
        }

        // Saved either way: the tested config is on disk. A failed reload is reported at the end.
        $reload = $this->webServers->driver()->reload();

        $application->save();

        if ($moved !== []) {
            app(ActivityLogger::class)->log('application.v7_leftovers_removed', $application, [
                'name' => $application->name,
                'files' => count($moved),
            ]);
        }

        // Switched off: empty its log, or old hits against removed rules reappear when it goes
        // back on. `-c`: nothing to empty is not an error. OLS marks the site's own access log
        // instead, which stays.
        if ($previousEnabled && ! $enabled && $this->webServers->driver()->name() !== 'openlitespeed') {
            $this->serverOps->run(
                ['truncate', '-c', '-s', '0', $application->wafDetectLogPath()],
                ['feature' => 'waf', 'op' => 'clear_log', 'application' => $application->id],
            );
        }

        // Only when the request carried a list: rewriting identical rows would reset their
        // timestamps.
        if ($rulesChange) {
            $application->wafRules()->delete();

            foreach ($this->pendingRules($exceptions, $customRules) as $rule) {
                $application->wafRules()->create(['type' => $rule->type, 'value' => $rule->value]);
            }
        }

        if ($reload->failed()) {
            throw new WebServerReloadFailedException($reload->reference);
        }
    }

    /**
     * Declares the ruleset once, server-wide, in the shape the active driver needs: nginx `map`
     * blocks (only allowed in the `http` context) or an Apache `SetEnvIf*` file that every
     * WAF-enabled site includes. OpenLiteSpeed needs neither; its templates embed the patterns.
     *
     * Static content, overwritten on every call: that is how existing sites pick up a newer
     * ruleset from a panel update.
     */
    private function ensureSharedMaps(): void
    {
        $driver = $this->webServers->driver()->name();

        $file = match ($driver) {
            'nginx' => ['resource' => '8g-firewall-maps.conf', 'path' => (string) config('server.waf.nginx_maps_path')],
            'apache' => ['resource' => '8g-apache-setenvif.conf', 'path' => (string) config('server.waf.apache_setenvif_path')],
            default => null,
        };

        if ($file === null) {
            return;
        }

        $contents = file_get_contents(resource_path('waf/'.$file['resource']));

        if ($contents === false) {
            return;
        }

        $this->serverOps->run(
            ['mkdir', '-p', dirname($file['path'])],
            ['feature' => 'waf', 'op' => 'ensure_shared_ruleset_dir'],
        );

        $written = $this->files->put($file['path'], $contents, ['feature' => 'waf', 'op' => 'write_shared_ruleset']);

        if ($written->failed()) {
            throw new WafOperationException($written->reference);
        }
    }

    /**
     * @param  array<int, string>  $exceptions
     * @param  array<int, string>  $customRules
     * @return Collection<int, ApplicationWafRule>
     */
    private function pendingRules(array $exceptions, array $customRules): Collection
    {
        $exceptionModels = collect($exceptions)->map(
            fn (string $value) => new ApplicationWafRule(['type' => 'exception', 'value' => $value]),
        );

        $blockModels = collect($customRules)->map(
            fn (string $value) => new ApplicationWafRule(['type' => 'block', 'value' => $value]),
        );

        return $exceptionModels->concat($blockModels)->values();
    }

    private function applyVhost(Application $application): ServerOpsResult
    {
        return $this->webServers->driver()->apply($application, $this->provisioner->documentRoot($application));
    }
}
