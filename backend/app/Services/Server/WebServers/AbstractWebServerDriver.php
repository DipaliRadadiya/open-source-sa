<?php

namespace App\Services\Server\WebServers;

use App\Contracts\WebServerDriver;
use App\Models\Application;
use App\Services\Server\ManagedFile;
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

        // The domain is validated to a hostname charset before it ever gets
        // here, so it cannot introduce a path separator.
        return "{$directory}/{$application->domain}.conf";
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
            'documentRoot' => $documentRoot,
            'phpVersion' => $application->php_version ?: config('server.default_php_version'),
            // The OS account the site runs as. nginx and Apache reach PHP
            // through a pool that already knows this; OLS spawns the process
            // itself and has to be told.
            'user' => $application->systemUser?->username,
        ];
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
     * @return array<int, string>
     */
    abstract protected function testCommand(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function reloadCommand(): array;
}
