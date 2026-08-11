<?php

return [

    // Where a certificate came from. Shown as a badge, so these read as nouns.
    'type' => [
        'letsencrypt' => 'Let\'s Encrypt',
        'custom' => 'Uploaded',
        'self_signed' => 'Self-signed',
    ],

    // Issuance failures, keyed by the code the panel classifies certbot's
    // output into. Each says what to do about it, because 'it failed' is the
    // least useful sentence a panel can produce about a certificate.
    'failed' => [
        'rate_limited' => 'Let\'s Encrypt has issued too many certificates for this domain recently. The limit resets a week after the oldest one — try again then, or upload a certificate instead.',
        'rate_limited_failures' => 'Too many failed attempts for this domain in the last hour. Let\'s Encrypt allows five; wait an hour before trying again.',
        'unreachable' => 'The validation request never reached this server. Check that port 80 is open and that nothing else is answering on it.',
        'dns_not_pointing' => 'The domain does not resolve to this server. Point its DNS record here, wait for it to propagate, then try again.',
        'challenge_not_served' => 'The validation file was not served correctly. The site may be redirecting or rewriting /.well-known — or a proxy such as Cloudflare is answering instead of this server.',
        'certbot_missing' => 'certbot is not installed on this server.',
        'no_certifiable_domains' => 'None of this site\'s domains are ready for a certificate. Verify DNS first.',
        'self_sign_failed' => 'The self-signed certificate could not be generated.',
        'file_missing' => 'The certificate file is missing from this server. Reissue it.',
        'unknown' => 'The certificate could not be issued.',
    ],

    // Why a certificate type is not on offer for this site. Each names the
    // thing the user would have to change, or says plainly that nothing can
    // be changed and points at the option that does work.
    'unavailable' => [
        'test_domain' => 'This site\'s only domains are temporary test domains (:domains). Let\'s Encrypt cannot issue a certificate for them, because they share one weekly limit with everyone else using that service. A self-signed certificate will encrypt this site now.',
        'dns_unverified' => 'None of this site\'s domains point at this server yet. Add a DNS A record for one, wait for it to propagate, then try again.',
        'self_signed_warning' => 'Encrypts traffic immediately and works on any domain, including test and internal ones. Browsers will show a warning, because nothing outside this server vouches for it.',
    ],

];
