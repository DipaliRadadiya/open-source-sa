<?php

namespace App\Services\Server\Certificates;

use App\Models\ApplicationDomain;
use App\Services\Server\Applications\DnsVerifier;
use App\Services\Server\ManagedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A dry run of the ACME HTTP-01 challenge, before certbot is allowed near it.
 *
 * A DNS lookup on its own is not enough, and the difference matters. DNS
 * pointing here says nothing about whether the token will actually be served:
 * port 80 can be firewalled, Cloudflare can be answering instead, or — most
 * often — the site's own rewrite rules swallow `/.well-known/` and hand the
 * request to index.php, which returns a 404 page. Let's Encrypt reads that as
 * an authorisation failure, and it allows **five per hostname per hour**. A
 * gate that lets those attempts through is barely a gate.
 *
 * So this does what Let's Encrypt is about to do: writes a random token into
 * the challenge directory, fetches it back over plain HTTP, and only says yes
 * if the exact token comes back. It costs nothing against any rate limit — the
 * request is our own — and when it fails it knows *which* of the failure modes
 * it was, which is the part a user can act on.
 *
 * SSRF: the request is pinned to the address the name resolved to, with the
 * hostname carried in a Host header, so it cannot be re-pointed between the
 * lookup and the fetch. Loopback and the cloud-metadata range are refused
 * outright rather than fetched.
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
     * @return array{domain: string, ok: bool, reason: ?string, resolved_ip: ?string}
     */
    public function check(ApplicationDomain $domain): array
    {
        // Refresh what we know about the name first. The stored flag was
        // written when the domain was added — which is usually before the user
        // has touched their registrar, so relying on it means a button that
        // stays disabled long after the problem is fixed.
        $this->dns->verify($domain);
        $domain->refresh();

        $ip = $domain->dns_resolved_ip;

        if ($ip === null) {
            return $this->result($domain, false, 'dns_missing');
        }

        if ($domain->behind_proxy) {
            // Resolvable, and still hopeless: the proxy answers the challenge
            // instead of this server. Its own reason because "DNS is fine but
            // SSL fails" is otherwise the most baffling outcome in the feature.
            return $this->result($domain, false, 'behind_proxy');
        }

        if ($this->blocked($ip)) {
            return $this->result($domain, false, 'blocked_ip');
        }

        // Resolves somewhere else entirely. Answered without fetching, for two
        // reasons: the message is exact ("points at 1.2.3.4"), where a fetch
        // would return somebody else's site and produce the misleading
        // "check your rewrite rules"; and it means this check only ever makes
        // a request to this server's own address, never to a third party the
        // domain happens to name.
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
            // We could not set up our own test, so we have learned nothing
            // about the domain. Reported as its own reason rather than blamed
            // on the user's DNS.
            return $this->result($domain, false, 'precheck_failed');
        }

        try {
            $reason = $this->fetch($domain->domain, $ip, $token);
        } finally {
            $this->files->delete($path, ['feature' => 'certificate', 'op' => 'remove_precheck_token']);
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
                // Plain HTTP on purpose: HTTP-01 is validated over port 80, and
                // a site that only works on 443 cannot be validated this way.
                ->withoutRedirecting()
                ->timeout(10)
                ->connectTimeout(5)
                ->get("http://{$ip}/.well-known/acme-challenge/{$token}");
        } catch (\Throwable) {
            return 'unreachable';
        }

        // A redirect is not a pass. Let's Encrypt follows them, but a site that
        // redirects the challenge to HTTPS before it holds a certificate sends
        // the validator somewhere that cannot answer — so treat it as a
        // problem the user should see now rather than a surprise later.
        if ($response->redirect()) {
            return 'challenge_redirected';
        }

        if (! $response->successful()) {
            return 'challenge_not_served';
        }

        // The body has to be the exact token. A site whose 404 page returns
        // HTTP 200 — WordPress does this in some configurations — would
        // otherwise look like a pass and fail for real a moment later.
        return trim($response->body()) === $token ? null : 'challenge_not_served';
    }

    /**
     * Addresses we will not fetch from.
     *
     * The name is user-supplied, so it could be pointed at the loopback
     * interface or the cloud metadata endpoint to turn this check into a
     * request the panel makes on someone's behalf. Neither could ever be a
     * legitimate target for a public certificate, so refusing costs nothing.
     */
    private function blocked(string $ip): bool
    {
        return str_starts_with($ip, '127.')
            || str_starts_with($ip, '169.254.')
            || str_starts_with($ip, '0.');
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
