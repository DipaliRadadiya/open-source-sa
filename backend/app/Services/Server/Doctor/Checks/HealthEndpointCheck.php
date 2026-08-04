<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Panel\InstalledPanelInfo;
use Illuminate\Support\Facades\Http;
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
 */
class HealthEndpointCheck implements DoctorCheck
{
    public function __construct(private InstalledPanelInfo $installed) {}

    public function key(): string
    {
        return 'health_endpoint';
    }

    public function run(): array
    {
        $url = rtrim((string) config('app.url'), '/').'/api/health';

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url);
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'detail' => 'no response from '.$url,
                'fix' => 'doctor.fixes.health_unreachable',
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => 'fail',
                'detail' => $url.' returned '.$response->status(),
                'fix' => 'doctor.fixes.health_unreachable',
            ];
        }

        $served = $response->json('health.version');
        $running = $this->installed->installed()['version'];

        if ($running !== null && $served !== $running) {
            return [
                'status' => 'warn',
                'detail' => 'serving '.var_export($served, true).', code is '.$running,
                'fix' => 'doctor.fixes.health_version_mismatch',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $url.' → '.($served ?? 'no version'),
            'fix' => null,
        ];
    }
}
