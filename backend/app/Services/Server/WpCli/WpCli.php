<?php

namespace App\Services\Server\WpCli;

use App\Models\Application;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;

/**
 * wp-cli, the one binary every WordPress operation in the panel goes through.
 *
 * Not part of a base system and not installed by `install.sh`: the first
 * WordPress install fetches it. Until then the health check reports it
 * missing and points at the setup page — which had no way to install it
 * (bug #4). v7 offers it as a setup step; so does this, through the same
 * download the WordPress installer uses, so the two cannot disagree on where
 * it comes from or where it lands.
 */
class WpCli
{
    public function __construct(private ServerOps $serverOps) {}

    public function path(): string
    {
        return (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp');
    }

    /**
     * wp-cli for one site, run by that site's own PHP: `/usr/bin/php7.4
     * /usr/local/bin/wp`, or the LiteSpeed binary on OpenLiteSpeed.
     *
     * The phar's `#!/usr/bin/env php` shebang picks the server default, which
     * need not be the version the site was given (measured 2026-10-28: a site
     * on 7.4 had staging, clone and magic login run on 8.4). The installer
     * already ran it this way; this is the same rule for everything else.
     * A site with no version set keeps the shebang — the server default is
     * what it runs on anyway.
     *
     * @return list<string>
     */
    public function command(Application $application): array
    {
        if (blank($application->php_version)) {
            return [$this->path()];
        }

        return [app(PhpRuntime::class)->binaryPath((string) $application->php_version), $this->path()];
    }

    /** Read from the box every time: anyone with root can remove it. */
    public function installed(): bool
    {
        return $this->serverOps->probe(['test', '-x', $this->path()], ['feature' => 'wp_cli', 'op' => 'check'])->ok;
    }

    /**
     * Download, then make executable. https only, redirects included, and
     * `--fail` so an error page is never written over the binary.
     *
     * @return array<int, array<int, string>>
     */
    public function installCommands(): array
    {
        return [
            [
                'curl', '--fail', '--location', '--silent', '--show-error',
                '--proto', '=https', '--proto-redir', '=https',
                '--output', $this->path(),
                (string) config('server.installers.wordpress.wp_cli_url'),
            ],
            ['chmod', '0755', $this->path()],
        ];
    }
}
