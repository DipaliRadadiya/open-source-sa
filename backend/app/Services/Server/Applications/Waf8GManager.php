<?php

namespace App\Services\Server\Applications;

use App\Enums\WafMode;
use App\Exceptions\Server\Application\WafOperationException;
use App\Models\Application;
use App\Models\ApplicationWafRule;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The 8G Firewall (query string / request URI / user agent / referrer /
 * cookie / method — six independently switchable categories, plus a
 * per-app exceptions and custom-rules list). Same apply → test → reload →
 * rollback shape as Basic Auth and the AI Bot Blocker, with one addition:
 * the ruleset is declared once, server-wide, rather than per vhost — see
 * `ensureSharedMaps()`.
 *
 * Rules (exceptions/custom blocks) are rendered from an in-memory
 * collection, not saved to the database, until the config test proves the
 * new state is safe — a failed test must leave the database exactly as it
 * was, the same guarantee every other apply-then-test feature here makes.
 */
class Waf8GManager
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
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
        // Refused before anything is written. Turning the firewall on where it
        // cannot be enforced used to answer 200 and store `waf_enabled: true`,
        // so the screen showed protection that inspected nothing. A 422 naming
        // the web server is the honest answer, and the frontend can hide the
        // control entirely using `waf_supported`.
        //
        // Only enabling is blocked: disabling, and editing rules on a site that
        // was enabled before this guard existed, must stay possible — otherwise
        // an OLS site that got switched on could never be switched back off.
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

        // A partial update must not decide anything it did not mention. Absent
        // once meant "all six on", which silently re-enabled the category a
        // user had switched off to fix a false positive.
        $rulesChange = $exceptions !== null || $customRules !== null;
        $exceptions ??= $application->wafRules->where('type', 'exception')->pluck('value')->all();
        $customRules ??= $application->wafRules->where('type', 'block')->pluck('value')->all();

        $application->waf_enabled = $enabled;
        $application->waf_mode = $mode;
        $application->waf_categories = $categories ?? $application->waf_categories;

        // Rendered against the new rules before either is ever written to
        // the database — see class docblock.
        $application->setRelation('wafRules', $this->pendingRules($exceptions, $customRules));

        $applied = $this->applyVhost($application);

        if ($applied->failed()) {
            throw new WafOperationException($applied->reference);
        }

        if ($this->webServers->driver()->test()->failed()) {
            $application->waf_enabled = $previousEnabled;
            $application->waf_mode = $previousMode;
            $application->waf_categories = $previousCategories;
            $application->load('wafRules');

            $restored = $this->applyVhost($application);

            throw new WafOperationException($restored->reference);
        }

        $this->webServers->driver()->reload();

        $application->save();

        // Only when the request actually carried a list. Rewriting the rows to
        // the same values on every save would churn the table and, worse,
        // reset their timestamps so "when did this rule appear" stops being
        // answerable.
        if (! $rulesChange) {
            return;
        }

        $application->wafRules()->delete();

        foreach ($this->pendingRules($exceptions, $customRules) as $rule) {
            $application->wafRules()->create(['type' => $rule->type, 'value' => $rule->value]);
        }
    }

    /**
     * Declares the ruleset once, server-wide, in whatever shape the active
     * driver needs it: nginx `map` blocks (nginx only allows `map` in the
     * `http` context, so a per-site copy is not an option) or the Apache
     * `SetEnvIfExpr`/`SetEnvIfNoCase` file every WAF-enabled site `Include`s
     * (not required by Apache the way it is by nginx, but one shared file
     * avoids duplicating ~15KB of regex into every site's own vhost).
     * OpenLiteSpeed needs neither — its templates embed the pattern list
     * directly, best-effort, the same as everywhere else OLS shows up.
     *
     * Static content, safe to overwrite unconditionally on every call — the
     * only way an already-configured site picks up a newer ruleset shipped
     * in a future panel update, the same accepted limitation the AI Bot
     * Blocker has for its own list.
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
