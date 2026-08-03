<?php

namespace App\Services\Server\Certificates;

use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Drives certbot in `certonly --webroot` mode.
 *
 * Not the `--nginx` / `--apache` plugins. They work by rewriting the vhost,
 * which the panel regenerates on every domain change — their edits would be
 * wiped and HTTPS would disappear with nothing to explain it. It is also the
 * only mode OpenLiteSpeed can use, since no certbot plugin exists for it.
 */
class CertbotClient
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
    ) {}

    /**
     * Request or extend a certificate covering exactly these names.
     *
     * @param  array<int, string>  $domains
     */
    public function issue(array $domains, string $email, int $applicationId): ServerOpsResult
    {
        $command = [
            (string) config('server.certificates.certbot'),
            'certonly',
            '--webroot',
            '--webroot-path', (string) config('server.certificates.challenge_root'),
            '--non-interactive',
            '--agree-tos',
            // The certificate is named after the first domain, which is the
            // primary. Pinned rather than left to certbot, which otherwise
            // appends -0001 to a name it has seen before and puts the files
            // somewhere the vhost is not looking.
            '--cert-name', $domains[0],
            // Adding a name to an existing certificate has to replace it, not
            // create a second one beside it.
            '--expand',
            // Renew rather than reissue when there is still time. Reissuing
            // spends the weekly duplicate-certificate limit for no gain — five
            // per identical name set per week, and a user clicking a button
            // that looks like it did nothing will click it again.
            '--keep-until-expiring',
        ];

        foreach ($domains as $domain) {
            $command[] = '-d';
            $command[] = $domain;
        }

        $command[] = $email !== '' ? '--email' : '--register-unsafely-without-email';

        if ($email !== '') {
            $command[] = $email;
        }

        return $this->serverOps->run(
            $command,
            ['feature' => 'certificate', 'op' => 'issue', 'application' => $applicationId],
            timeout: (int) config('server.certificates.timeout'),
        );
    }

    /**
     * Stop renewing a certificate and delete its files.
     */
    public function revoke(string $certName, int $applicationId): ServerOpsResult
    {
        return $this->serverOps->run(
            [
                (string) config('server.certificates.certbot'),
                'delete', '--non-interactive', '--cert-name', $certName,
            ],
            ['feature' => 'certificate', 'op' => 'delete', 'application' => $applicationId],
            timeout: (int) config('server.certificates.timeout'),
        );
    }

    /**
     * Turn certbot's output into a code the panel can translate.
     *
     * Deliberately not stored verbatim: certbot's messages contain paths, order
     * URLs and occasionally the account key location, and a raw stderr line
     * shown to a user is both a leak and unreadable. What matters is which of a
     * handful of things went wrong, because each has a different fix.
     */
    public function classify(string $output): string
    {
        $text = strtolower($output);

        return match (true) {
            // The important one to get right. Hitting a rate limit is not
            // "something went wrong, try again" — trying again is exactly what
            // must not happen, and the wait is measured in hours or a week.
            str_contains($text, 'too many certificates') => 'rate_limited',
            str_contains($text, 'too many failed authorizations') => 'rate_limited_failures',

            // The challenge file was never fetched. Almost always DNS pointing
            // elsewhere, a firewall, or a proxy answering on port 80.
            str_contains($text, 'connection refused'),
            str_contains($text, 'timeout during connect') => 'unreachable',

            str_contains($text, 'dns problem'),
            str_contains($text, 'nxdomain') => 'dns_not_pointing',

            // The token was served but came back wrong — usually the site's own
            // rewrite rules swallowing /.well-known.
            str_contains($text, 'unauthorized'),
            str_contains($text, '404') => 'challenge_not_served',

            str_contains($text, 'command not found'),
            str_contains($text, 'certbot: not found') => 'certbot_missing',

            default => 'unknown',
        };
    }

    /**
     * Make sure the shared challenge directory exists and is world-readable.
     *
     * The web server reads the token as its own unprivileged user, so a
     * directory only root can enter produces a 403 and an authorisation failure
     * that looks exactly like DNS pointing at the wrong host.
     */
    public function ensureChallengeRoot(): ServerOpsResult
    {
        $root = rtrim((string) config('server.certificates.challenge_root'), '/');

        return $this->serverOps->run(
            ['install', '-d', '-m', '0755', $root.'/.well-known/acme-challenge'],
            ['feature' => 'certificate', 'op' => 'ensure_challenge_root'],
        );
    }

    /**
     * Install the post-renewal hook.
     *
     * Without it renewal quietly half-works: certbot's timer writes a new
     * certificate to disk and the web server carries on serving the old one
     * from memory until something unrelated reloads it. The failure surfaces
     * weeks later as an expired certificate on a site whose files are fine.
     */
    public function ensureRenewalHook(string $reloadCommand): ServerOpsResult
    {
        $dir = rtrim((string) config('server.certificates.renewal_hook_dir'), '/');

        $this->serverOps->run(
            ['install', '-d', '-m', '0755', $dir],
            ['feature' => 'certificate', 'op' => 'ensure_hook_dir'],
        );

        $script = <<<SH
        #!/bin/sh
        # Managed by the panel. Reloads the web server after certbot renews, so
        # the new certificate is actually served rather than sitting on disk.
        {$reloadCommand}
        SH;

        $path = $dir.'/sv-oss-reload';

        $written = $this->files->put($path, $script."\n", [
            'feature' => 'certificate', 'op' => 'write_renewal_hook',
        ]);

        if ($written->failed()) {
            return $written;
        }

        return $this->serverOps->run(
            ['chmod', '0755', $path],
            ['feature' => 'certificate', 'op' => 'chmod_renewal_hook'],
        );
    }

    /**
     * Where certbot put the files for this certificate name.
     *
     * @return array{certificate: string, private_key: string, chain: string}
     */
    public function paths(string $certName): array
    {
        $dir = rtrim((string) config('server.certificates.live_dir'), '/').'/'.$certName;

        return [
            'certificate' => $dir.'/fullchain.pem',
            'private_key' => $dir.'/privkey.pem',
            'chain' => $dir.'/chain.pem',
        ];
    }
}
