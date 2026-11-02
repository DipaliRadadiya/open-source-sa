<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Panel\InstalledPanelInfo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Does the panel answer over HTTP, as itself?
 *
 * Every other check runs inside PHP and so proves only that PHP works. This
 * one goes out through the web server and back, which is the only way to catch
 * a vhost pointing at the wrong root, a wrong APP_URL, or php-fpm listening on
 * a socket nginx cannot reach.
 *
 * It asserts the version matches too. A 200 alone would be just as happy
 * talking to a different panel on the same box.
 *
 * Tried up to three times, 1 s then 2 s apart, but only when nothing answered
 * or the answer was a 5xx. The installer runs this check while Ubuntu's own
 * first-boot updates may still be going, and their needrestart hook can restart
 * php-fpm or the web server under it: one unlucky probe said "no response" over
 * a panel that worked a second later. A 4xx or a wrong version is an answer,
 * and is reported at once.
 */
class HealthEndpointCheck implements DoctorCheck
{
    /** How many times the panel is asked before the check fails. */
    public const ATTEMPTS = 3;

    public function __construct(private InstalledPanelInfo $installed) {}

    public function key(): string
    {
        return 'health_endpoint';
    }

    public function run(): array
    {
        $url = rtrim((string) config('app.url'), '/').'/api/health';

        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->retry([1000, 2000], when: fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->get($url);
        } catch (Throwable $e) {
            // Guzzle's cURL text ("cURL error 28: Connection timed out after
            // 5001 ms", "Could not resolve host"): the URL and the cause, and
            // nothing secret. It is what tells a firewall from a DNS problem.
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.health_no_response_reason', [
                    'url' => $url,
                    'attempts' => self::ATTEMPTS,
                    'reason' => Str::limit(Str::squish($e->getMessage()), 200),
                ]),
                'fix' => 'doctor.fixes.health_unreachable',
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.health_status', ['url' => $url, 'status' => $response->status()]),
                'fix' => 'doctor.fixes.health_unreachable',
            ];
        }

        $served = $response->json('health.version');
        $running = $this->installed->installed()['version'];

        if ($running !== null && $served !== $running) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.health_version', ['served' => var_export($served, true), 'running' => $running]),
                'fix' => 'doctor.fixes.health_version_mismatch',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $served === null
                ? __('doctor.details.health_ok_no_version', ['url' => $url])
                : __('doctor.details.health_ok', ['url' => $url, 'version' => $served]),
            'fix' => null,
        ];
    }
}
