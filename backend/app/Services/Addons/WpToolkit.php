<?php

namespace App\Services\Addons;

use App\Exceptions\Addons\AddonException;
use App\Models\Application;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;

/**
 * The WordPress toolkit (sv-wp-toolkit): WP-CLI for one site, run as the
 * site's owner, under the site's own PHP.
 */
class WpToolkit extends AddonCli
{
    public function __construct(
        ServerOps $serverOps,
        private PhpRuntime $php,
        private WebServerManager $webServers,
    ) {
        parent::__construct($serverOps);
    }

    public function name(): string
    {
        return 'wp-toolkit';
    }

    public function label(): string
    {
        return 'WP Toolkit';
    }

    protected function binary(): string
    {
        return (string) config('server.addons.wp_toolkit.binary');
    }

    /**
     * What tells the toolkit which site: its owner, its WordPress root, and
     * its PHP. Without `--php`, WP-CLI runs under the server's default PHP —
     * a 7.4 site on an 8.4 box loads its plugins under 8.4.
     *
     * @return array<int, string>
     */
    public function target(Application $application): array
    {
        $user = $application->systemUser?->username;

        if (blank($user)) {
            throw AddonException::of('addon_command_failed', $this->label(), __('errors/addons.no_system_user'));
        }

        $target = ['--system-user', $user, '--path', $application->documentRoot()];

        $version = (string) $application->php_version;
        $binary = preg_match('/^\d+\.\d+$/', $version) === 1 ? $this->php->binaryPath($version) : '';

        // A bare `php` is the operator choosing whatever is on PATH, which is
        // what leaving the flag off already means.
        if ($binary !== '' && $binary !== 'php') {
            array_push($target, '--php', $binary);
        }

        return $target;
    }

    /**
     * What the `security` commands take instead: they write web server rules
     * into the site's rules directory, not WordPress settings.
     *
     * @return array<int, string>
     */
    public function rulesTarget(Application $application): array
    {
        return ['--web-server', $this->webServers->driver()->name(), '--rules-dir', $application->siteRulesPath()];
    }
}
