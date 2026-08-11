<?php

namespace App\Services\Server\Applications;

use App\Enums\DomainType;
use App\Models\Application;
use App\Services\Server\ServerOps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Assembles every health-check signal an application dashboard needs in one call.
 *
 * Each check is independently null-safe — a site with no workers returns no
 * worker issue, not an error. The detector returns an empty array for a healthy
 * site.
 *
 * @return Collection<int, array{type: string, severity: string, message: string, meta: array<string, mixed>}>
 */
class AppIssueDetector
{
    public function __construct(
        private ProcessSupervisor $processSupervisor,
        private ServerOps $serverOps,
        // The document root is derived from the slug and the system user's
        // home, which is the provisioner's job — the model has never had a
        // `documentRoot()` of its own.
        private ApplicationProvisioner $provisioner,
        // Resolution goes through the same service the domain screen uses,
        // rather than a bare `gethostbyname()` here: one DNS mechanism with
        // one set of semantics, and a seam a test can replace instead of
        // reaching the real network.
        private DnsVerifier $dns,
    ) {}

    /**
     * @return Collection<int, array{type: string, severity: string, message: string, meta: array<string, mixed>}>
     */
    public function issues(Application $app): Collection
    {
        return collect([
            $this->checkCertificate($app),
            $this->checkWorkers($app),
            $this->checkDnsDrift($app),
            $this->checkPhpEol($app),
            $this->checkDiskUsage($app),
            $this->checkDeployFailed($app),
        ])->filter();
    }

    /**
     * @return array{type: 'certificate', severity: 'warning'|'critical', message: string, meta: array<string, mixed>}|null
     */
    private function checkCertificate(Application $app): ?array
    {
        $cert = $app->certificate;

        if (! $cert) {
            return null;
        }

        if ($cert->expired()) {
            return [
                'type' => 'certificate',
                'severity' => 'critical',
                'message' => __('app_dashboard.issues.certificate.expired'),
                'meta' => [
                    'expires_at' => $cert->expires_at?->format('d-m-Y H:i:s'),
                    'days_remaining' => $cert->daysRemaining(),
                ],
            ];
        }

        $days = $cert->daysRemaining();
        if ($days !== null && $days <= 30) {
            return [
                'type' => 'certificate',
                'severity' => $days <= 7 ? 'critical' : 'warning',
                'message' => __('app_dashboard.issues.certificate.expiring', ['days' => $days]),
                'meta' => [
                    'expires_at' => $cert->expires_at?->format('d-m-Y H:i:s'),
                    'days_remaining' => $days,
                ],
            ];
        }

        return null;
    }

    /**
     * @return array{type: 'worker', severity: 'warning', message: string, meta: array<string, mixed>}|null
     */
    private function checkWorkers(Application $app): ?array
    {
        if (! $this->processSupervisor->runs($app)) {
            return null;
        }

        $status = $this->processSupervisor->status($app);

        // No status = not running (the status() call returns null when
        // systemctl is-active returns non-zero, which is the signal for a
        // stopped or crashed unit).
        if ($status !== null) {
            return null;
        }

        return [
            'type' => 'worker',
            'severity' => 'warning',
            'message' => __('app_dashboard.issues.worker.stopped'),
            'meta' => [
                'unit' => $this->processSupervisor->unit($app),
            ],
        ];
    }

    /**
     * @return array{type: 'dns', severity: 'warning', message: string, meta: array<string, mixed>}|null
     */
    private function checkDnsDrift(Application $app): ?array
    {
        // Only check the primary domain, and only when a resolved IP was stored.
        $domain = $app->domains->firstWhere('type', DomainType::Primary);

        if (! $domain) {
            return null;
        }

        if (! $domain->dns_resolved_ip) {
            return null;
        }

        $currentIp = $this->dns->resolve($domain->domain);

        if ($currentIp === null) {
            // DNS lookup failed — domain may not be pointing to this server yet.
            // Flag as an issue but with no current IP to compare.
            return [
                'type' => 'dns',
                'severity' => 'warning',
                'message' => __('app_dashboard.issues.dns.unresolved'),
                'meta' => [
                    'domain' => $domain->domain,
                    'stored_ip' => $domain->dns_resolved_ip,
                ],
            ];
        }

        if ($currentIp !== $domain->dns_resolved_ip) {
            return [
                'type' => 'dns',
                'severity' => 'warning',
                'message' => __('app_dashboard.issues.dns.drift'),
                'meta' => [
                    'domain' => $domain->domain,
                    'stored_ip' => $domain->dns_resolved_ip,
                    'current_ip' => $currentIp,
                ],
            ];
        }

        return null;
    }

    /**
     * @return array{type: 'php_eol', severity: 'warning', message: string, meta: array<string, mixed>}|null
     */
    private function checkPhpEol(Application $app): ?array
    {
        $version = $app->php_version;

        if (! $version) {
            return null;
        }

        $eolDate = self::PHP_EOL_DATES[$version] ?? null;

        if (! $eolDate) {
            return null;
        }

        if (now()->lt(CarbonImmutable::parse($eolDate))) {
            return null;
        }

        return [
            'type' => 'php_eol',
            'severity' => 'warning',
            'message' => __('app_dashboard.issues.php_eol', ['version' => $version]),
            'meta' => [
                'php_version' => $version,
                'eol_date' => CarbonImmutable::parse($eolDate)->format('d-m-Y'),
            ],
        ];
    }

    /**
     * @return array{type: 'disk', severity: 'warning'|'critical', message: string, meta: array<string, mixed>}|null
     */
    private function checkDiskUsage(Application $app): ?array
    {
        $documentRoot = $this->provisioner->documentRoot($app->loadMissing('systemUser'));

        // Run df on the document root's mount point, not on every subdirectory.
        // Using `-k` (1K blocks) keeps the output stable across systems.
        $result = $this->serverOps->run(
            ['df', '-k', dirname($documentRoot)],
            ['feature' => 'app_dashboard', 'op' => 'disk_usage'],
        );

        if (! $result->ok) {
            return null; // Cannot determine — do not flag.
        }

        // Parse the last line of df output (the filesystem row).
        $lines = array_filter(array_map('trim', explode("\n", $result->output())));
        $lastLine = end($lines);

        // df -k output: Filesystem 1K-blocks Used Available Use% Mounted
        $parts = preg_split('/\s+/', $lastLine);

        if (! is_array($parts) || count($parts) < 5) {
            return null;
        }

        $usedPercent = (int) rtrim($parts[4], '%');

        if ($usedPercent < 90) {
            return null;
        }

        return [
            'type' => 'disk',
            'severity' => $usedPercent >= 95 ? 'critical' : 'warning',
            'message' => __('app_dashboard.issues.disk', ['percent' => $usedPercent]),
            'meta' => [
                'used_percent' => $usedPercent,
                'mount' => $parts[5] ?? $documentRoot,
            ],
        ];
    }

    /**
     * @return array{type: 'deploy_failed', severity: 'warning', message: string, meta: array<string, mixed>}|null
     */
    private function checkDeployFailed(Application $app): ?array
    {
        if ($app->failed_step === null) {
            return null;
        }

        return [
            'type' => 'deploy_failed',
            'severity' => 'warning',
            'message' => __('app_dashboard.issues.deploy_failed', ['step' => $app->failed_step]),
            'meta' => [
                'failed_step' => $app->failed_step,
                'reference' => $app->reference,
            ],
        ];
    }

    /**
     * PHP EOL dates — December 1 of each EOL year (standard PHP schedule).
     *
     * Plain strings, not `DateTimeImmutable` instances: PHP allows `new` in a
     * parameter default or an attribute argument, but *not* in a class
     * constant. Written that way, this file could not be loaded at all — the
     * detector fatal'd on `New expressions are not supported in this context`
     * before a single issue was ever checked.
     *
     * @var array<string, string>
     */
    private const PHP_EOL_DATES = [
        '8.0' => '2023-12-01',
        '8.1' => '2026-12-01',
        '8.2' => '2027-12-01',
        '8.3' => '2028-12-01',
        '8.4' => '2030-12-01',
    ];
}
