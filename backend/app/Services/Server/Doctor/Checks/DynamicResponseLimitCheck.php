<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\ServerOps;
use App\Support\Bytes;

/**
 * Can this server hand back a large file?
 *
 * OpenLiteSpeed bounds the body of anything PHP produces with
 * `maxDynRespSize`, and it ships at 2047M. The panel serves downloads through
 * PHP — it must, because the bytes belong to the site's own Linux user and
 * only the panel holds the sudoers grant to read them — so that cap is a hard
 * ceiling on the size of any file the file manager can return. Over it, the
 * request is refused with a bare 413 before PHP runs at all, which reaches the
 * browser as "this site can't be reached" and reaches the panel's own logs as
 * nothing whatsoever.
 *
 * nginx and Apache have no equivalent limit on a proxied or FastCGI response
 * body, so this check passes trivially there rather than inventing an
 * equivalent — one of the places the three web servers genuinely differ.
 *
 * `install.sh` raises this on a fresh install. It exists as a check because
 * the updater ships code, never configuration, so every panel installed before
 * that change keeps the ceiling until an operator moves it — and the only
 * symptom is a download that fails with no entry anywhere to explain it.
 */
class DynamicResponseLimitCheck implements DoctorCheck
{
    private const CONFIG_PATH = '/usr/local/lsws/conf/httpd_config.conf';

    /**
     * Below this, a download of an ordinary disk image or database dump fails.
     * Not a recommendation — the shipped 2047M is simply too small for what
     * the file manager is for.
     */
    private const MINIMUM_BYTES = 8 * 1024 * 1024 * 1024;

    public function __construct(
        private ServerCapabilities $capabilities,
        private ServerOps $serverOps,
    ) {}

    public function key(): string
    {
        return 'dynamic_response_limit';
    }

    public function run(): array
    {
        if ($this->capabilities->recordedWebServer() !== 'openlitespeed') {
            return [
                'status' => 'pass',
                'detail' => 'not OpenLiteSpeed — no dynamic response cap applies',
                'fix' => null,
            ];
        }

        // Read through sudo, not `file_get_contents`. The file itself is
        // world-readable but `/usr/local/lsws/conf` is `drwxr-x---` owned by
        // lsadm, so the panel account cannot traverse to it and the direct
        // read returns "not readable" on every OpenLiteSpeed box — a check
        // that can never answer, which is barely a check at all.
        $result = $this->serverOps->run(
            ['cat', self::CONFIG_PATH],
            ['feature' => 'doctor', 'op' => 'read_ols_config'],
            timeout: 15,
        );

        if (! $result->answered) {
            return [
                'status' => 'warn',
                'detail' => self::CONFIG_PATH.' could not be read, so the download ceiling is unknown'
                    .' (reference '.$result->reference.')',
                'fix' => 'doctor.fixes.dynamic_response_limit',
            ];
        }

        $configured = $this->configuredBytes($result->output());

        if ($configured === null) {
            return [
                'status' => 'warn',
                'detail' => 'no maxDynRespSize found in '.self::CONFIG_PATH,
                'fix' => 'doctor.fixes.dynamic_response_limit',
            ];
        }

        if ($configured < self::MINIMUM_BYTES) {
            return [
                'status' => 'fail',
                'detail' => 'maxDynRespSize is '.Bytes::human($configured)
                    .', so any download larger than that is refused with a 413 before the panel sees it',
                'fix' => 'doctor.fixes.dynamic_response_limit',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => 'maxDynRespSize '.Bytes::human($configured),
            'fix' => null,
        ];
    }

    /**
     * The directive's value in bytes.
     *
     * Suffixes are OpenLiteSpeed's own (`M`, `G`), and a bare number is bytes.
     * Returns null when the directive is absent, which is reported separately
     * from a value that is merely too low — "I could not find out" and "this
     * is wrong" need different advice.
     */
    private function configuredBytes(string $config): ?int
    {
        if (! preg_match('/^\s*maxDynRespSize\s+(\d+)([MGK]?)\s*$/mi', $config, $matches)) {
            return null;
        }

        return (int) $matches[1] * match (strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
    }
}
