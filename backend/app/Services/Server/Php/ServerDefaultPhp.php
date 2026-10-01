<?php

namespace App\Services\Server\Php;

use App\Services\Server\Runtimes\PhpRuntime;

/**
 * The PHP version a new site starts on: the server's default.
 *
 * "Make default" on the PHP screen moves the `php` command
 * (`PhpRuntime::default()`), while new sites went on starting from
 * `server.default_php_version` — written once by install.sh. After making 8.3
 * the default the create form still offered 8.4 (operator's call 2026-10-01:
 * one default, meaning the same thing everywhere). Existing sites are never
 * moved: each stores its own version.
 *
 * The configured value is the fallback for when the server has no default the
 * panel recognises (a version removed while it was the default, a hand-made
 * link). Asked once per request or job — the site-type catalog reads it once
 * per type — and never kept longer, so a default changed a minute ago counts.
 */
class ServerDefaultPhp
{
    private bool $resolved = false;

    private ?string $version = null;

    public function __construct(private PhpRuntime $runtime) {}

    public function version(): ?string
    {
        if (! $this->resolved) {
            $this->version = $this->runtime->default()
                ?: ((string) config('server.default_php_version', '') ?: null);
            $this->resolved = true;
        }

        return $this->version;
    }
}
