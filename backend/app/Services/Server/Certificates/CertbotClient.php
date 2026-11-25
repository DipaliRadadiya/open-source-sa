<?php

namespace App\Services\Server\Certificates;

use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Drives certbot in `certonly --webroot` mode.
 *
 * Not the `--nginx` / `--apache` plugins: they rewrite the vhost, which the panel regenerates on
 * every domain change, so HTTPS would vanish. Also the only mode OpenLiteSpeed can use.
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
    /**
     * @param  string  $certName  the lineage: the site's name, as v7 names it (v7 layout B3)
     */
    public function issue(array $domains, string $email, int $applicationId, string $certName): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->certonly($domains, $email, $certName, [
                // Renew rather than reissue when there is still time: reissuing spends the weekly
                // duplicate-certificate limit (five per identical name set).
                '--keep-until-expiring',
            ]),
            ['feature' => 'certificate', 'op' => 'issue', 'application' => $applicationId],
            timeout: (int) config('server.certificates.timeout'),
        );
    }

    /**
     * Perform the same request against Let's Encrypt's staging server and keep
     * nothing — certbot's own `--dry-run`.
     *
     * The panel's reachability check only proves *this server* serves the token; only ACME can
     * tell whether Let's Encrypt will accept the name (CAA record, account problem, rate limit).
     *
     * `--dry-run` pins the staging endpoint and skips every save path, so it cannot replace a live
     * certificate, and deploy hooks (where the web-server reload lives) do not run.
     *
     * `--force-renewal` rather than `--keep-until-expiring`: with nothing due, certonly exits **0**
     * without validating anything. See `confirmedDryRun()`, which refuses to read that as a pass.
     *
     * @param  array<int, string>  $domains
     */
    public function dryRun(array $domains, string $email, int $applicationId, string $certName): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->certonly($domains, $email, $certName, ['--dry-run', '--force-renewal']),
            ['feature' => 'certificate', 'op' => 'dry_run', 'application' => $applicationId],
            timeout: (int) config('server.certificates.timeout'),
        );
    }

    /**
     * Did the simulation actually simulate?
     *
     * Read off the output, never the exit code: certonly exits 0 both when it completed a staging
     * authorisation and when it decided there was nothing to do. certbot prints this exact line
     * only on the path that ran.
     */
    public function confirmedDryRun(string $output): bool
    {
        return str_contains(strtolower($output), 'the dry run was successful');
    }

    /**
     * The shared `certonly --webroot` invocation, plus whatever distinguishes the caller. One place,
     * so a dry run exercises the command that will actually be issued.
     *
     * @param  array<int, string>  $domains
     * @param  array<int, string>  $extra
     * @return array<int, string>
     */
    private function certonly(array $domains, string $email, string $certName, array $extra): array
    {
        $command = [
            (string) config('server.certificates.certbot'),
            'certonly',
            '--webroot',
            '--webroot-path', (string) config('server.certificates.challenge_root'),
            '--non-interactive',
            '--agree-tos',
            // Named after the primary domain. Pinned because certbot otherwise appends -0001 to a
            // name it has seen before and puts the files where the vhost is not looking.
            '--cert-name', $certName,
            // Adding a name must replace the existing certificate, not create a second one.
            '--expand',
            ...$extra,
        ];

        foreach ($domains as $domain) {
            $command[] = '-d';
            $command[] = $domain;
        }

        $command[] = $email !== '' ? '--email' : '--register-unsafely-without-email';

        if ($email !== '') {
            $command[] = $email;
        }

        return $command;
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
     * Deliberately not stored verbatim: certbot's messages contain paths, order URLs and
     * occasionally the account key location. Each of the few outcomes has a different fix.
     */
    public function classify(string $output): string
    {
        $text = strtolower($output);

        return match (true) {
            // Hitting a rate limit is not "try again": retrying is what must not happen, and the
            // wait is measured in hours or a week.
            str_contains($text, 'too many certificates') => 'rate_limited',
            str_contains($text, 'too many failed authorizations') => 'rate_limited_failures',

            // The challenge file was never fetched. Almost always DNS pointing
            // elsewhere, a firewall, or a proxy answering on port 80.
            str_contains($text, 'connection refused'),
            str_contains($text, 'timeout during connect') => 'unreachable',

            str_contains($text, 'dns problem'),
            str_contains($text, 'nxdomain') => 'dns_not_pointing',

            // The token was served but came back wrong, usually the site's own rewrite rules
            // swallowing /.well-known. The 404 is matched in context: a bare `404` appears in ACME
            // order URLs, byte counts and timestamps.
            str_contains($text, 'unauthorized'),
            str_contains($text, 'status 404'),
            str_contains($text, '"404"'),
            str_contains($text, '404 not found') => 'challenge_not_served',

            str_contains($text, 'command not found'),
            str_contains($text, 'certbot: not found') => 'certbot_missing',

            // certonly decided there was nothing to do and exited 0. Never reached from `issue()`
            // (`--keep-until-expiring`); from `dryRun()` it must read as "proved nothing", not a pass.
            str_contains($text, 'not yet due for renewal') => 'dry_run_skipped',

            default => 'unknown',
        };
    }

    /**
     * Make sure the shared challenge directory exists and is world-readable.
     *
     * The web server reads the token as its unprivileged user: a directory only root can enter
     * gives a 403 that looks exactly like DNS pointing at the wrong host.
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
     * Without it, certbot's timer writes the new certificate to disk while the web server keeps
     * serving the old one from memory, until it expires on a site whose files are fine.
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
