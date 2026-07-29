<?php

namespace App\Services\Server\WebServers;

use App\Contracts\WebServerDriver;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

abstract class AbstractWebServerDriver implements WebServerDriver
{
    public function __construct(protected ServerOps $serverOps) {}

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

        return View::make($view, [
            'application' => $application,
            'domain' => $application->domain,
            'documentRoot' => $documentRoot,
            'phpVersion' => $application->php_version ?: config('server.default_php_version'),
        ])->render();
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
