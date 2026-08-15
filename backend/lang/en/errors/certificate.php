<?php

return [

    'no_certifiable_domains' => 'No domain on this application is ready for a certificate. Verify DNS first.',
    'force_https_without_certificate' => 'HTTPS cannot be forced without an active certificate — the site would stop responding.',
    'not_pem' => 'This does not look like a PEM file. It should begin with -----BEGIN.',
    'key_mismatch' => 'The private key does not match the certificate.',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        'dns_missing' => ':domain does not resolve at all. Add a DNS A record pointing it at this server, then try again.',
        'dns_not_pointing' => ':domain points at :ip, which is not this server.',
        'dns_unverifiable' => 'This server is behind NAT, so the panel cannot confirm from here that :domain points at it. If DNS is correct, use Issue anyway — the validation request arrives from outside and will succeed.',
        'behind_proxy' => ':domain points at Cloudflare, not this server, so the validation request never arrives. Pause the proxy (grey cloud) while the certificate is issued.',
        'blocked_ip' => ':domain points at :ip, which is not a public address a certificate can be issued for.',
        'unreachable' => 'Nothing answered on port 80 for :domain. Check that the firewall allows port 80 and that the web server is running.',
        'challenge_redirected' => ':domain redirects the validation request instead of answering it. Turn off any HTTP-to-HTTPS redirect until the certificate is issued.',
        'challenge_not_served' => ':domain answered, but not with the validation file. The site is most likely rewriting /.well-known/ — check its rewrite rules.',
        'precheck_failed' => 'The validation file could not be written on this server, so :domain could not be checked.',
    ],
];
