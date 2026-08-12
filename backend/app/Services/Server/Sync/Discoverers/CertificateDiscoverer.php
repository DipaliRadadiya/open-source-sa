<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Models\ApplicationDomain;
use App\Models\Certificate;
use App\Models\SyncRun;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ServerOps;

/**
 * Let's Encrypt certificates already issued on the box.
 *
 * Without these an adopted site reads as having no TLS while it is serving
 * HTTPS perfectly well — and the panel would offer to issue a certificate for
 * a name that already has one, spending a weekly rate limit to replace
 * something that was working.
 *
 * Only certbot's own `live` directory is read. An uploaded certificate cannot
 * be adopted: the panel stores the private key of those encrypted, and a key
 * sitting on disk is not one the panel was given.
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

            // certbot names the directory after the certificate's first name,
            // sometimes with a `-0001` suffix when it has been re-issued under
            // a changed set of names.
            $name = preg_replace('/-\d{4}$/', '', basename($path)) ?? basename($path);

            $domain = ApplicationDomain::query()
                ->with('application')
                ->whereRaw('LOWER(domain) = ?', [strtolower($name)])
                ->first();

            if ($domain?->application === null) {
                // A certificate for a name the panel does not serve. Left
                // alone rather than attached to the nearest site — a
                // certificate on the wrong application is worse than one the
                // panel simply does not know about.
                continue;
            }

            $application = $domain->application;

            // One certificate per application, enforced by the schema. A site
            // that already has one has already been answered for.
            if (Certificate::query()->where('application_id', $application->id)->exists()) {
                continue;
            }

            $certificatePath = $path.'/fullchain.pem';
            $expiresAt = $this->files->expiresAt($certificatePath);

            $found[] = [
                'key' => $name,
                'label' => $name,
                'confidence' => 100,
                'evidence' => [
                    'path' => $path,
                    'application' => $application->domain,
                    'expires_at' => $expiresAt?->toDateString(),
                    // An expired certificate is still worth adopting: the
                    // panel can then say so, which is the whole point.
                    'expired' => $expiresAt !== null && $expiresAt->isPast(),
                ],
                'attributes' => [
                    'application_id' => $application->id,
                    'domains' => [$name],
                    'certificate_path' => $certificatePath,
                    'private_key_path' => $path.'/privkey.pem',
                    'chain_path' => $path.'/chain.pem',
                    'expires_at' => $expiresAt,
                ],
            ];
        }

        return $found;
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        return Certificate::create([
            'application_id' => $attributes['application_id'],
            'type' => CertificateType::LetsEncrypt,
            'status' => CertificateStatus::Active,
            'domains' => $attributes['domains'],
            'certificate_path' => $attributes['certificate_path'],
            'private_key_path' => $attributes['private_key_path'],
            'chain_path' => $attributes['chain_path'],
            'expires_at' => $attributes['expires_at'],
            // certbot's own timer renews these, and it was renewing them
            // before the panel arrived. Recording auto_renew as false would
            // describe a certificate that expires when it does not.
            'auto_renew' => true,
            // Not inferred from the certificate existing: whether the site
            // redirects to HTTPS is a vhost decision, and the panel has not
            // read the vhost for it. Claiming it here would be a guess about
            // a setting the user can see for themselves.
            'force_https' => false,
        ]);
    }
}
