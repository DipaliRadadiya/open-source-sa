<?php

namespace App\Services\Server\WebServers;

class NginxDriver extends AbstractWebServerDriver
{
    public function name(): string
    {
        return 'nginx';
    }

    protected function testCommand(): array
    {
        return ['nginx', '-t'];
    }

    protected function reloadCommand(): array
    {
        return ['systemctl', 'reload', 'nginx'];
    }
}
