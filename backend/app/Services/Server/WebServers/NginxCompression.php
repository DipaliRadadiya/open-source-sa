<?php

namespace App\Services\Server\WebServers;

/**
 * Compression for more than HTML on nginx, as v7 has it.
 *
 * Ubuntu's nginx.conf turns gzip on and leaves the other gzip lines commented,
 * so only `text/html` was compressed — CSS, JavaScript and JSON went out at
 * full size. v7's nginx.conf is Ubuntu's with five of those lines switched on
 * (`gzip_vary` stays commented); this makes the same change in the same file
 * (Dipali, 2026-11-11: v7's places).
 *
 * Only a line nothing sets yet: a second one at the http level is a duplicate
 * nginx refuses, and a server from v7 has them all already. A commented line
 * is switched on in place; one that is missing goes under `gzip on;`. A
 * server without `gzip on;`, or with `gzip off;`, is the owner's decision and
 * is left alone. Tested before the reload and put back when the test fails.
 */
class NginxCompression
{
    /** v7's values, as its nginx.conf has them. */
    private const DIRECTIVES = [
        'gzip_proxied' => 'any',
        'gzip_comp_level' => '6',
        'gzip_buffers' => '16 8k',
        'gzip_http_version' => '1.1',
        'gzip_types' => 'text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript',
    ];

    /** Where the panel wrote these before it used v7's place (2026-11-11). */
    private const LEGACY = '/etc/nginx/conf.d/panel-gzip.conf';

    public function __construct(
        private WebServerManager $webServers,
        private SharedConfigFile $shared,
    ) {}

    /**
     * @return array{status: 'updated'|'current'|'skipped'|'failed', reference: ?string}
     */
    public function ensure(): array
    {
        if ($this->webServers->driver()->name() !== 'nginx') {
            return ['status' => 'skipped', 'reference' => null];
        }

        $context = ['feature' => 'web_server', 'op' => 'compression', 'web_server' => 'nginx'];
        $path = (string) config('server.web_server_drivers.nginx.config_path');
        $config = $this->shared->read($path, $context);

        if ($config === null || preg_match('/^\s*gzip\s+on\s*;/m', $config) !== 1) {
            return ['status' => 'skipped', 'reference' => null];
        }

        // The rest of the http level: conf.d, minus the panel's old file.
        $others = $this->shared->otherConfigs([], [dirname($path).'/conf.d'], [self::LEGACY], $context);

        foreach (self::DIRECTIVES as $name => $value) {
            if (preg_match('/^[ \t]*'.$name.'\s/m', $config.$others) === 1) {
                continue;
            }

            $line = "{$name} {$value};";

            // Ubuntu's own commented line, switched on where it stands.
            $config = preg_replace('/^([ \t]*)#[ \t]*'.$name.'\s[^\n]*$/m', '${1}'.$line, $config, 1, $count);

            if ($count === 0) {
                $config = preg_replace('/^([ \t]*)gzip\s+on\s*;[^\n]*$/m', '$0'."\n".'${1}'.$line, $config, 1);
            }
        }

        return $this->shared->apply([$path => $config, self::LEGACY => null], [], $context);
    }
}
