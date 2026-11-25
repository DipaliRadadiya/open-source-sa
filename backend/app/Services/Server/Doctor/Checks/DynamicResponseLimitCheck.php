<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\ServerOps;
use App\Support\Bytes;

/**
 * Can this server hand back a large file?
 *
 * OpenLiteSpeed bounds the body of anything PHP produces with `maxDynRespSize` (default 2047M).
 * The panel serves downloads through PHP (only it holds the sudoers grant to read a site user's
 * files), so that cap is a hard ceiling on any file the file manager can return. Over it the
 * request gets a bare 413 before PHP runs, with nothing in the panel's logs.
 *
 * nginx and Apache have no equivalent limit, so the check passes trivially there.
 *
 * `install.sh` raises the limit on a fresh install; the updater ships code, never configuration,
 * so older installs keep the ceiling until an operator moves it.
 */
class DynamicResponseLimitCheck implements DoctorCheck
{
    private const CONFIG_PATH = '/usr/local/lsws/conf/httpd_config.conf';

    /**
     * Below this, a download of an ordinary disk image or database dump fails.
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
                'detail' => __('doctor.details.dyn_not_ols'),
                'fix' => null,
            ];
        }

        // Read through sudo, not `file_get_contents`: `/usr/local/lsws/conf` is `drwxr-x---` owned
        // by lsadm, so the panel account cannot traverse to the file.
        $result = $this->serverOps->run(
            ['cat', self::CONFIG_PATH],
            ['feature' => 'doctor', 'op' => 'read_ols_config'],
            timeout: 15,
        );

        if (! $result->answered) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.dyn_unreadable', ['path' => self::CONFIG_PATH, 'reference' => $result->reference]),
                'fix' => 'doctor.fixes.dynamic_response_limit',
            ];
        }

        $configured = $this->configuredBytes($result->output());

        if ($configured === null) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.dyn_absent', ['path' => self::CONFIG_PATH]),
                'fix' => 'doctor.fixes.dynamic_response_limit',
            ];
        }

        if ($configured < self::MINIMUM_BYTES) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.dyn_low', ['size' => Bytes::human($configured)]),
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
     * Suffixes are OpenLiteSpeed's own (`M`, `G`); a bare number is bytes. Null when the
     * directive is absent, reported separately from a value that is merely too low.
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
