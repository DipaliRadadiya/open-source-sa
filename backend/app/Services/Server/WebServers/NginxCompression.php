<?php

namespace App\Services\Server\WebServers;

/**
 * Compression for more than HTML on nginx.
 *
 * Ubuntu's nginx.conf turns gzip on and leaves every other gzip line
 * commented, so only `text/html` was ever compressed — CSS, JavaScript and
 * JSON went out at full size. v7's nginx.conf sets these lines; this writes
 * the same values beside it, in conf.d, because nginx.conf is the server
 * owner's file.
 *
 * Only the lines nothing else sets: a second `gzip_types` at the http level is
 * a duplicate nginx refuses, and a server moved from v7 already has them all.
 * `gzip off` is the owner's decision and is left alone.
 */
class NginxCompression
{
    /** v7's values, as its nginx.conf has them. */
    private const DIRECTIVES = [
        'gzip_vary' => 'on',
        'gzip_proxied' => 'any',
        'gzip_comp_level' => '6',
        'gzip_buffers' => '16 8k',
        'gzip_http_version' => '1.1',
        'gzip_types' => 'text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript',
    ];

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
        $path = (string) config('server.web_server_drivers.nginx.compression');
        $others = $this->shared->otherConfigs([(string) config('server.web_server_drivers.nginx.config_path')], [dirname($path)], [$path], $context);

        if (preg_match('/^\s*gzip\s+off\s*;/m', $others) === 1) {
            return ['status' => 'skipped', 'reference' => null];
        }

        $lines = [];

        foreach (self::DIRECTIVES as $name => $value) {
            if (preg_match('/^\s*'.$name.'\s/m', $others) !== 1) {
                $lines[] = "{$name} {$value};";
            }
        }

        if ($lines === []) {
            // The server sets them all itself; a copy of ours would only be a duplicate.
            $removed = $this->shared->remove($path, null, $context);

            return $removed['status'] === 'current' ? ['status' => 'skipped', 'reference' => null] : $removed;
        }

        $contents = "# Managed by the panel: compress text, scripts and data as well as HTML (as v7 does).\n".implode("\n", $lines)."\n";

        return $this->shared->publish($path, $contents, null, $context);
    }
}
