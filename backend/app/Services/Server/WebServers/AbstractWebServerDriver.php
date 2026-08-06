<?php

namespace App\Services\Server\WebServers;

use App\Contracts\WebServerDriver;
use App\Enums\AiBotPolicy;
use App\Enums\DomainType;
use App\Enums\WafMode;
use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

abstract class AbstractWebServerDriver implements WebServerDriver
{
    public function __construct(
        protected ServerOps $serverOps,
        protected ManagedFile $files,
    ) {}

    /**
     * One file in a directory, which is true for nginx and Apache. A driver
     * whose configuration is not one file overrides this.
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult
    {
        return $this->files->put(
            $this->configPath($application),
            $this->renderConfig($application, $documentRoot),
            ['feature' => 'application', 'op' => 'write_config', 'application' => $application->id],
        );
    }

    public function remove(Application $application): ServerOpsResult
    {
        return $this->files->delete(
            $this->configPath($application),
            ['feature' => 'application', 'op' => 'remove_config', 'application' => $application->id],
        );
    }

    public function configPath(Application $application): string
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_dir"), '/');

        // Named after the application, not its domain. A domain is mutable and
        // was never unique, so two sites could claim one and silently overwrite
        // each other's vhost, and changing a domain orphaned the old file under
        // a name nothing could address any more. The slug is unique, is a
        // filename by construction, and belongs to the site rather than to one
        // of its names.
        return "{$directory}/{$this->fileName($application)}.conf";
    }

    /**
     * Falls back to the domain for a row that predates the slug column, so a
     * site provisioned before this still resolves to the file it actually has
     * on disk rather than to a name nothing was ever written under.
     */
    protected function fileName(Application $application): string
    {
        return (string) ($application->slug ?: $application->domain);
    }

    public function renderConfig(Application $application, string $documentRoot): string
    {
        $profile = $application->serving_profile;
        $view = "server.vhosts.{$this->name()}.{$profile}";

        abort_unless(View::exists($view), 500);

        return View::make($view, $this->viewData($application, $documentRoot))->render();
    }

    /**
     * What a vhost template is given. A driver whose syntax needs more than
     * this adds to it.
     *
     * @return array<string, mixed>
     */
    protected function viewData(Application $application, string $documentRoot): array
    {
        return [
            'application' => $application,
            'domain' => $application->domain,
            // Every name the site answers to, primary first. Templates used to
            // hardcode `www.{domain}` alongside the primary; that guess is now
            // a row in application_domains, backfilled for existing sites so
            // what they serve is unchanged.
            'serverNames' => $application->serverNames(),
            // Redirects get their own server block — they serve nothing, they
            // send a 301 somewhere else, and mixing them into the main block
            // would serve the same content under both names instead.
            'redirects' => $application->domains
                ->filter(fn ($domain) => $domain->type === DomainType::Redirect)
                ->values(),
            // Only a certificate with files behind it. A pending or failed one
            // is deliberately hidden from the template: pointing a server block
            // at a path that is not there fails the config test and takes a
            // working site down over a certificate it never had.
            'certificate' => $application->certificate?->servable() ? $application->certificate : null,
            'forceHttps' => (bool) ($application->certificate?->servable() && $application->certificate->force_https),
            // The shared ACME webroot, aliased into every profile. Per-site
            // document roots cannot work for node and proxy sites — they serve
            // nothing from disk, so there is nowhere for certbot to drop the
            // challenge token.
            'challengeRoot' => rtrim((string) config('server.certificates.challenge_root'), '/'),
            'documentRoot' => $documentRoot,
            'phpVersion' => $application->php_version ?: config('server.default_php_version'),
            // Where PHP actually is for this site. An isolated site has its own
            // pool running as its own user; everything else still shares the
            // server-wide pool, which is what every site did before pools
            // existed. Resolved here so no template has to know the rule.
            'phpSocket' => app(PoolManager::class)->socketFor($application),
            // The OS account the site runs as. nginx and Apache reach PHP
            // through a pool that already knows this; OLS spawns the process
            // itself and has to be told.
            'user' => $application->systemUser?->username,
            // Where a reverse proxy sends traffic, and a name for the backend
            // that is unique per application — OpenLiteSpeed declares external
            // applications by name, and two sites sharing one would have the
            // second quietly overwrite the first.
            'appPort' => $application->app_port,
            'appName' => 'sv-app-'.$application->id,
            // Null when off, so every template gates the whole block behind
            // one `@if` instead of re-checking `basic_auth_enabled` itself.
            // The credential file lives inside `.panel/`, which every profile
            // already denies serving over HTTP — nothing new to hide here.
            'basicAuth' => $application->basic_auth_enabled ? [
                'realm' => 'sv-app-'.$application->id,
                'htpasswdPath' => $documentRoot.'/.panel/.htpasswd',
            ] : null,
            // A single regex-ready, alternation-joined string — already
            // escaped — or null when the policy blocks nothing. Resolved
            // here, once, so no template re-implements "which bots does this
            // policy block" against `config/ai_bots.php` itself.
            'botBlock' => $this->botBlockPattern($application),
            'waf' => $this->wafViewData($application, $documentRoot),
        ];
    }

    /**
     * Null unless the firewall is on and actually has something to check —
     * zero categories and zero custom rules means nothing would ever be
     * blocked, so the template renders no block at all rather than an
     * `<RequireAny>`/`if` chain with nothing inside it.
     *
     * @return array{mode: string, categories: array<int, string>, exceptions: array<int, string>, customRules: array<int, string>, detectLogPath: string}|null
     */
    private function wafViewData(Application $application, string $documentRoot): ?array
    {
        if (! $application->waf_enabled) {
            return null;
        }

        $categories = $application->wafActiveCategories();
        // `wafRules` lazy-loads from the database if not already set on the
        // model — which also means a caller can pre-set it in memory (see
        // Waf8GManager::apply()) to render against not-yet-saved rules
        // without a second query silently overwriting them.
        $customRules = $application->wafRules->where('type', 'block')->pluck('value')->all();

        if ($categories === [] && $customRules === []) {
            return null;
        }

        $exceptions = $application->wafRules->where('type', 'exception')->pluck('value')->all();

        return [
            'mode' => $application->waf_mode instanceof WafMode ? $application->waf_mode->value : (string) $application->waf_mode,
            'categories' => $categories,
            'exceptions' => $exceptions,
            'customRules' => $customRules,
            'detectLogPath' => $documentRoot.'/.panel/waf-detect.log',
        ];
    }

    /**
     * The policy's built-in list, plus this site's own additions, minus its
     * own exemptions.
     *
     * Exemptions are subtracted last and case-insensitively, so "allow this
     * one" wins over both the built-in list and a contradictory custom block
     * — a rule that says allow and a rule that says block can only have one
     * safe resolution, and the one that keeps traffic flowing is it.
     *
     * `botRules` lazy-loads if not already set, which also lets a caller
     * pre-set it in memory (see BotBlockerManager::apply()) to render against
     * not-yet-saved rules without a second query overwriting them.
     */
    private function botBlockPattern(Application $application): ?string
    {
        $bots = $application->ai_bot_policy instanceof AiBotPolicy
            ? $application->ai_bot_policy->blockedBots()
            : [];

        $rules = $application->botRules;

        $bots = array_merge($bots, $rules->where('type', 'block')->pluck('value')->all());

        $allowed = $rules->where('type', 'allow')
            ->map(fn ($rule) => mb_strtolower((string) $rule->value))
            ->all();

        $bots = array_values(array_filter(
            array_unique($bots),
            fn (string $bot) => ! in_array(mb_strtolower($bot), $allowed, true),
        ));

        if ($bots === []) {
            return null;
        }

        return implode('|', array_map(fn (string $bot) => preg_quote($bot, '/'), $bots));
    }

    public function test(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->testCommand(),
            ['feature' => 'application', 'op' => 'config_test', 'web_server' => $this->name()],
        );
    }

    public function reload(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->reloadCommand(),
            ['feature' => 'application', 'op' => 'reload', 'web_server' => $this->name()],
        );
    }

    /**
     * The reload command as something else can run.
     *
     * Exposed for certbot's post-renewal hook, which is a shell script run by
     * certbot's own timer rather than by the panel. Without it renewal
     * half-works: a new certificate lands on disk and the web server keeps
     * serving the old one from memory until something unrelated reloads it,
     * which surfaces weeks later as an expired certificate on a healthy site.
     *
     * @return array<int, string>
     */
    public function reloadCommandForHook(): array
    {
        return $this->reloadCommand();
    }

    /**
     * @return array<int, string>
     */
    abstract protected function testCommand(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function reloadCommand(): array;
}
