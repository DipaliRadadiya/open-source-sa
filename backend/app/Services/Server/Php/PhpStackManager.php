<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Php\Stacks\FpmPhpStack;

/**
 * Resolves the PHP stack from the web server this box runs.
 *
 * One server, one web server, one PHP stack — the same rule the panel already
 * applies to vhosts. OpenLiteSpeed gets LSPHP; everything else gets FPM.
 *
 * An unrecognised or unset web server resolves to FPM rather than failing.
 * That is the current behaviour and the majority case, and a panel that
 * refuses to describe PHP because it cannot name the web server would be
 * worse than one that assumes the common answer.
 */
class PhpStackManager
{
    public function __construct(private ServerCapabilities $capabilities) {}

    public function stack(): PhpStack
    {
        $class = config('server.php_stacks.'.$this->key().'.driver')
            ?? config('server.php_stacks.fpm.driver')
            ?? FpmPhpStack::class;

        return app($class);
    }

    /**
     * Which stack this server uses, without resolving it.
     */
    public function key(): string
    {
        // Read from the capability registry, the same source the vhost driver
        // uses, so the two can never disagree about what this box is — but
        // from the recorded value only. Detection shells out, and this is
        // asked by anything that wants a PHP path; a read that probes the
        // server is a surprise nobody budgets for.
        $webServer = $this->capabilities->recordedWebServer();

        return (string) (config("server.web_server_drivers.{$webServer}.php_stack") ?? 'fpm');
    }
}
