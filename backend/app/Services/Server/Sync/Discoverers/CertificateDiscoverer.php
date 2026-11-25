<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\Certificate;
use App\Models\SyncRun;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ServerOps;

/**
 * Let's Encrypt certificates already issued on the box.
 *
 * Without these an adopted site reads as having no TLS while it serves HTTPS, and the panel
 * would offer to issue a certificate for a name that already has one, spending a weekly rate
 * limit to replace a working one.
 *
 * Reads certbot's own `live` directory, plus one uploaded pair per site at the paths v7 used
 * (and the panel uses). Nothing else on disk is read.
 */
class CertificateDiscoverer implements Discoverable
{
    public function __construct(
        private ServerOps $serverOps,
        private CertificateFiles $files,
    ) {}

    public function resourceType(): string
    {
        return 'certificate';
    }

    public function dependsOn(): array
    {
        // A certificate belongs to a site, matched by the names it covers.
        return ['application'];
    }

    public function discover(SyncRun $run): array
    {
        $liveDir = rtrim((string) config('server.certificates.live_dir', '/etc/letsencrypt/live'), '/');

        $listing = $this->serverOps->run(
            ['find', $liveDir, '-maxdepth', '1', '-mindepth', '1', '-type', 'd'],
            ['feature' => 'sync', 'op' => 'discover_certificates'],
            timeout: 30,
        );

        if ($listing->failed()) {
            // No certbot, or nothing issued. Both normal.
            return [];
        }

        $found = [];

        foreach (preg_split('/\r?\n/', trim($listing->output())) ?: [] as $path) {
            $path = rtrim(trim($path), '/');

            if ($path === '') {
                continue;
            }

            // certbot names the directory after the first name, with a `-0001` suffix when
            // re-issued under a changed set of names.
            $name = preg_replace('/-\d{4}$/', '', basename($path)) ?? basename($path);

            $domain = ApplicationDomain::query()
                ->with('application')
                ->whereRaw('LOWER(domain) = ?', [strtolower($name)])
                ->first();

            $application = $domain?->application;
            $certificatePath = $path.'/fullchain.pem';
            $domains = [$name];

            // v7 and the panel name the lineage after the application (`--cert-name {app}`),
            // which is no domain. Taken only when the certificate covers one of the site's names.
            if ($application === null && ($bySlug = Application::query()->with('domains')->where('slug', basename($path))->first()) !== null) {
                $pem = $this->serverOps->probe(['cat', '--', $certificatePath], ['feature' => 'sync', 'op' => 'read_certificate']);
                $names = $pem->ok ? $this->files->subjectNames($pem->output()) : [];
                $covering = new Certificate(['domains' => $names]);

                if (collect($bySlug->domainNames())->contains(fn (string $d): bool => $covering->covers($d))) {
                    [$application, $domains] = [$bySlug, $names];
                }
            }

            if ($application === null) {
                // A name the panel does not serve. Never attached to the nearest site: a
                // certificate on the wrong application is worse than an unknown one.
                continue;
            }

            // One certificate per application (schema). Skip a site that already has one,
            // including one found earlier in this listing.
            if (Certificate::query()->where('application_id', $application->id)->exists()
                || in_array($application->id, array_column(array_column($found, 'attributes'), 'application_id'), true)) {
                continue;
            }

            $expiresAt = $this->files->expiresAt($certificatePath);

            $found[] = [
                'key' => $name,
                'label' => $name,
                'confidence' => 100,
                'evidence' => [
                    'path' => $path,
                    'application' => $application->domain,
                    'expires_at' => $expiresAt?->toDateString(),
                    // An expired certificate is still adopted, so the panel can say so.
                    'expired' => $expiresAt !== null && $expiresAt->isPast(),
                ],
                'attributes' => [
                    'application_id' => $application->id,
                    'domains' => $domains,
                    'certificate_path' => $certificatePath,
                    'private_key_path' => $path.'/privkey.pem',
                    'chain_path' => $path.'/chain.pem',
                    'expires_at' => $expiresAt,
                ],
            ];
        }

        return [...$found, ...$this->discoverV7Uploads($found)];
    }

    /**
     * Certificates v7 installed from an upload, at `/etc/ssl/certs/{app}.crt` and
     * `/etc/ssl/private/{app}.key` (the paths the panel writes too). Adopted as uploaded
     * certificates, with v7's separate chain file when there is one, and only when
     * CertificateFiles::v7Pair() agrees the pair is this site's own.
     *
     * @param  array<int, array<string, mixed>>  $found  Let's Encrypt certificates already found
     * @return array<int, array<string, mixed>>
     */
    private function discoverV7Uploads(array $found): array
    {
        $taken = array_column(array_column($found, 'attributes'), 'application_id');
        $items = [];

        $applications = Application::query()
            ->with('domains')
            ->whereDoesntHave('certificate')
            ->whereNotIn('id', $taken)
            ->get();

        foreach ($applications as $application) {
            $pair = $this->files->v7Pair((string) $application->slug, $application->domainNames());

            if ($pair === null) {
                continue;
            }

            $expiresAt = $this->files->expiresAt($pair['certificate']);

            $items[] = [
                'key' => 'custom:'.$application->slug,
                'label' => $application->domain,
                'confidence' => 100,
                'evidence' => [
                    'path' => $pair['certificate'],
                    'application' => $application->domain,
                    'chain' => $pair['chain'],
                    'expires_at' => $expiresAt?->toDateString(),
                    'expired' => $expiresAt !== null && $expiresAt->isPast(),
                ],
                'attributes' => [
                    'type' => CertificateType::Custom->value,
                    'application_id' => $application->id,
                    'domains' => $pair['domains'],
                    'certificate_path' => $pair['certificate'],
                    'private_key_path' => $pair['private_key'],
                    'chain_path' => $pair['chain'],
                    'expires_at' => $expiresAt,
                ],
            ];
        }

        return $items;
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        if (($attributes['type'] ?? null) === CertificateType::Custom->value) {
            return Certificate::create([
                'application_id' => $attributes['application_id'],
                'type' => CertificateType::Custom,
                'status' => CertificateStatus::Active,
                'domains' => $attributes['domains'],
                'certificate_path' => $attributes['certificate_path'],
                'private_key_path' => $attributes['private_key_path'],
                'chain_path' => $attributes['chain_path'],
                'expires_at' => $attributes['expires_at'],
                // Nothing renews an uploaded certificate, here or in v7.
                'auto_renew' => false,
                'force_https' => false,
            ]);
        }

        return Certificate::create([
            'application_id' => $attributes['application_id'],
            'type' => CertificateType::LetsEncrypt,
            'status' => CertificateStatus::Active,
            'domains' => $attributes['domains'],
            'certificate_path' => $attributes['certificate_path'],
            'private_key_path' => $attributes['private_key_path'],
            'chain_path' => $attributes['chain_path'],
            'expires_at' => $attributes['expires_at'],
            // certbot's own timer already renews these.
            'auto_renew' => true,
            // Not inferred from the certificate: the HTTPS redirect is a vhost decision the
            // panel has not read.
            'force_https' => false,
        ]);
    }
}
