<?php

namespace App\Services\Server\Certificates;

use App\Models\ApplicationDomain;
use App\Services\Server\Applications\DnsVerifier;
use App\Services\Server\ManagedFile;
use App\Support\IpRange;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A dry run of the ACME HTTP-01 challenge, before certbot is allowed near it.
 *
 * DNS alone is not enough: port 80 can be firewalled, a proxy can answer instead, or the site's
 * rewrite rules can swallow `/.well-known/`. Let's Encrypt allows only five authorisation
 * failures per hostname per hour.
 *
 * So this writes a random token into the challenge directory, fetches it back over plain HTTP and
 * passes only if the exact token returns. It costs no rate limit and says which failure mode hit.
 *
 * SSRF: the request is pinned to the address the name resolved to, with the hostname in a Host
 * header, so it cannot be re-pointed between lookup and fetch. Loopback, private and
 * cloud-metadata ranges are refused outright.
 */
class AcmeReachabilityCheck
{
    public function __construct(
        private DnsVerifier $dns,
        private ManagedFile $files,
        private CertbotClient $certbot,
    ) {}

    /**
     * @param  iterable<ApplicationDomain>  $domains
     * @return array<int, array{domain: string, ok: bool, reason: ?string, resolved_ip: ?string}>
     */
    public function checkAll(iterable $domains): array
    {
        $this->certbot->ensureChallengeRoot();

        $results = [];

        foreach ($domains as $domain) {
            $results[] = $this->check($domain);
        }

        return $results;
    }

    /**
     * The sentence for one result, in the reader's locale.
     *
     * Shared by the 422 that refuses an issue request and the dry-run report, so both explain
     * the check the same way.
     *
     * @param  array{domain: string, ok: bool, reason: ?string, resolved_ip: ?string}  $result
     */
    public function describe(array $result): string
    {
        return __('errors/certificate.precheck.'.($result['ok'] ? 'ok' : $result['reason']), [
            'domain' => $result['domain'],
            'ip' => $result['resolved_ip'] ?? '—',
        ]);
    }

    /**
     * @return array{domain: string, ok: bool, reason: ?string, resolved_ip: ?string}
     */
    public function check(ApplicationDomain $domain): array
    {
        // Refresh DNS first: the stored flag dates from when the domain was added, usually before
        // the user fixed their registrar.
        $this->dns->verify($domain);
        $domain->refresh();

        $ip = $domain->dns_resolved_ip;

        if ($ip === null) {
            return $this->result($domain, false, 'dns_missing');
        }

        if ($domain->behind_proxy) {
            // The proxy answers the challenge instead of this server.
            return $this->result($domain, false, 'behind_proxy');
        }

        if ($this->blocked($ip)) {
            return $this->result($domain, false, 'blocked_ip');
        }

        // A name pointing elsewhere is answered without fetching: the message is exact, and the
        // check never makes a request to a third party's address.
        //
        // $server is null when the machine cannot know its public address (NAT'd cloud instances
        // see only a private one). That must not be compared, and not refused either: the fetch
        // below is the better evidence, so an unknown IP falls through to it.
        $server = $this->dns->serverIp();

        if ($server !== null && $ip !== $server) {
            return $this->result($domain, false, 'dns_not_pointing');
        }

        $token = Str::random(43);
        $path = rtrim((string) config('server.certificates.challenge_root'), '/')
            .'/.well-known/acme-challenge/'.$token;

        $written = $this->files->put($path, $token."\n", [
            'feature' => 'certificate', 'op' => 'write_precheck_token',
        ]);

        if ($written->failed()) {
            // Our own test could not be set up; do not blame the user's DNS.
            return $this->result($domain, false, 'precheck_failed');
        }

        try {
            $reason = $this->fetch($domain->domain, $ip, $token);
        } finally {
            $this->files->delete($path, ['feature' => 'certificate', 'op' => 'remove_precheck_token']);
        }

        // Nothing answered and our public address is unknown: a NAT'd box that cannot reach itself,
        // where the real challenge still succeeds because it arrives from outside. Do not report
        // a firewall problem.
        if ($reason === 'unreachable' && $server === null) {
            $reason = 'dns_unverifiable';
        }

        return $this->result($domain, $reason === null, $reason);
    }

    /**
     * Fetch the token the way Let's Encrypt will. Returns null on success, or
     * the reason it failed.
     */
    private function fetch(string $domain, string $ip, string $token): ?string
    {
        try {
            $response = Http::withHeaders(['Host' => $domain])
                // Plain HTTP on purpose: HTTP-01 is validated over port 80.
                ->withoutRedirecting()
                ->timeout(10)
                ->connectTimeout(5)
                ->get("http://{$ip}/.well-known/acme-challenge/{$token}");
        } catch (\Throwable) {
            return 'unreachable';
        }

        // A redirect is not a pass: one to HTTPS before a certificate exists sends the validator
        // somewhere that cannot answer.
        if ($response->redirect()) {
            return 'challenge_redirected';
        }

        if (! $response->successful()) {
            return 'challenge_not_served';
        }

        // The body must be the exact token: some sites return their 404 page with HTTP 200.
        return trim($response->body()) === $token ? null : 'challenge_not_served';
    }

    /**
     * Addresses we will not fetch from.
     *
     * The name is user-supplied, so it could point at loopback, the cloud metadata endpoint or an
     * internal network and turn this check into a request made on someone's behalf. None can hold
     * a public certificate, so refuse them all (private ranges included).
     */
    private function blocked(string $ip): bool
    {
        return IpRange::isPrivate($ip);
    }

    /**
     * @return array{domain: string, ok: bool, reason: ?string, resolved_ip: ?string}
     */
    private function result(ApplicationDomain $domain, bool $ok, ?string $reason): array
    {
        return [
            'domain' => $domain->domain,
            'ok' => $ok,
            'reason' => $reason,
            'resolved_ip' => $domain->dns_resolved_ip,
        ];
    }
}
