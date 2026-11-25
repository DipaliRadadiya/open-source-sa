<?php

namespace App\Services\Server\Applications;

use App\Enums\CertificateStatus;
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
        // The document root comes from the provisioner; the model has no `documentRoot()`.
        private ApplicationProvisioner $provisioner,
        // Same DNS service as the domain screen: one mechanism, and a seam tests can replace.
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

        // Only an active certificate can be near expiry. A pending row exists from the moment
        // the application is created and has no `expires_at`; a failed issue is `reason`'s to report.
        if ($cert->status !== CertificateStatus::Active) {
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
                // `trans_choice` so one day reads "1 day", not "1 days".
                'message' => trans_choice('app_dashboard.issues.certificate.expiring', $days, ['days' => $days]),
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

        // status() runs `systemctl show`, which exits 0 for a stopped or crashed unit too, so
        // read the state. Only "down" states count; an unreadable state is not evidence.
        if ($status !== null && ! in_array($status['state'], ['inactive', 'failed', 'deactivating'], true)) {
            return null;
        }

        return [
            'type' => 'worker',
            'severity' => 'warning',
            'message' => __('app_dashboard.issues.worker.stopped'),
            'meta' => [
                'unit' => $this->processSupervisor->unit($app),
                'state' => $status['state'] ?? null,
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
            // Lookup failed: the domain may not point here yet. Flag it without a current IP.
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

        // `-k` (1K blocks) keeps the output stable across systems.
        $result = $this->serverOps->run(
            ['df', '-k', dirname($documentRoot)],
            ['feature' => 'app_dashboard', 'op' => 'disk_usage'],
        );

        if (! $result->ok) {
            return null; // Cannot determine — do not flag.
        }

        // Last line of df output is the filesystem row.
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
     * PHP EOL dates: December 1 of each EOL year.
     *
     * Plain strings: `new` is not allowed in a class constant.
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
