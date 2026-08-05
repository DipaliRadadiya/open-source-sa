{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
docRoot                   {{ $documentRoot }}
vhDomain                  {{ $serverNames[0] }}
@if (count($serverNames) > 1 || $redirects->isNotEmpty())
vhAliases                 {{ implode(', ', array_merge(array_slice($serverNames, 1), $redirects->pluck('domain')->all())) }}
@endif
enableGzip                1

@if ($certificate)
{{-- OpenLiteSpeed keeps TLS on the vhost as well as the listener: the listener
     decides that 443 is answered, this decides which certificate is presented
     for this site. Without it a second site on the box is served the first
     one's certificate and every visitor gets a name-mismatch warning. --}}
vhssl {
  keyFile                 {{ $certificate->private_key_path }}
  certFile                {{ $certificate->certificate_path }}
  certChain               1
  {{-- TLS 1.2 as the floor. OLS spells the version set as a bitmask-style
       list; 1.0 and 1.1 are deprecated and fail PCI checks, and allowing them
       buys compatibility only with browsers that stopped receiving security
       updates years ago. --}}
  sslProtocol             24
}
@endif

{{-- The ACME challenge is served from one shared directory rather than the
     site's own document root, so node and proxy sites — which serve nothing
     from disk — have somewhere for certbot to drop the token. Declared as its
     own context so the rewrite below cannot swallow it: a WordPress site would
     otherwise hand the token to index.php and answer with its 404 page, which
     Let's Encrypt reads as unauthorized and which costs one of five attempts
     an hour. --}}
context /.well-known/acme-challenge {
  location                {{ $challengeRoot }}/.well-known/acme-challenge
  allowBrowse             1
  addDefaultCharset       off
}

@if ($basicAuth)
{{-- Best-effort: OLS's realm/userDB syntax has not been exercised against
     real hardware, unlike the nginx and Apache blocks above (see the
     project's other OLS notes on this same gap). `context /` is declared
     explicitly only when protection is on, so an unprotected site's config
     is byte-for-byte what it always was — the ACME context above is more
     specific and matches first, so it is never affected either way. --}}
context / {
  location                {{ $documentRoot }}
  allowBrowse             1
  realm                   {{ $basicAuth['realm'] }}
}

realm {{ $basicAuth['realm'] }} {
  userDB {
    location               {{ $basicAuth['htpasswdPath'] }}
    userNameSeparator      :
  }
}
@endif

errorlog $VH_ROOT/logs/error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog $VH_ROOT/logs/access.log {
  useServer               0
  rollingSize             10M
  keepDays                30
}

index {
  useServer               0
  indexFiles              index.html
}

{{-- No script handler at all. A static site that can execute PHP is a static
     site one upload away from not being static. --}}

{{-- `exp:` is OpenLiteSpeed's regex context; nginx's `~` fails the config
     test. Named directories rather than a lookahead, so .well-known stays
     reachable for certificate issuance. --}}
context exp:^/\.(git|svn|hg|bzr|env) {
  allowBrowse             0
}

{{-- A rewrite block only when there is a redirect to serve. OLS routes these
     names here as aliases, so they must be sent on explicitly or they would
     serve the site under a second name. --}}
@if ($redirects->isNotEmpty() || $forceHttps)
rewrite {
  enable                  1
@if ($forceHttps)
  {{-- Force HTTPS. The ACME exclusion is not optional: without it renewal
       stops working, and the redirect goes on pointing confidently at a
       certificate that has expired. --}}
  RewriteCond %{HTTPS} !=on
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteRule ^/?(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
@endif
@foreach ($redirects as $redirect)
  RewriteCond %{HTTP_HOST} ^{{ preg_quote($redirect->domain, '/') }}$ [NC]
  RewriteRule ^/?(.*)$ {{ $redirect->redirect_to ?: 'https://'.$domain }}/$1 [R={{ $redirect->redirect_status }},L]
@endforeach
}
@endif
