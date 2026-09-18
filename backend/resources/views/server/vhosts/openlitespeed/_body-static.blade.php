{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
{{--
    The file that DESCRIBES a site. Its declaration — `<name>.conf` — carries
    the listeners, vhDomain, rewrite rules and TLS, because that is the half
    OpenLiteSpeed needs in order to route a request here at all.

    Derived from the single-file template rather than rewritten, so the reasons
    written into it survive.
--}}
docRoot                   {{ $documentRoot }}
enableGzip                1


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
context exp:^/\.(git|svn|hg|bzr|env|panel) {
  allowBrowse             0
}

{{-- A rewrite block only when there is something to rewrite — a redirect,
     HTTPS-force, or an active bot policy. OLS routes redirect names here as
     aliases, so they must be sent on explicitly or they would serve the
     site under a second name. --}}
@if (! $certificate || $redirects->isNotEmpty() || $forceHttps || $botBlock)
rewrite {
  enable                  1
@if (! $certificate)
  RewriteCond %{HTTPS} =on
  RewriteRule ^ - [F,L]
@endif
@if ($botBlock)
  {{-- Checked first — a blocked bot gets [F] (403) immediately, ahead of
       HTTPS-force or any redirect. Apache mod_rewrite syntax, which OLS
       implements here, not nginx's. --}}
  RewriteCond %{HTTP_USER_AGENT} ({{ $botBlock }}) [NC]
  RewriteRule ^ - [F,L]
@endif
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
  RewriteRule ^/?(.*)$ {{ $redirect->redirect_to ?: $canonicalUrl }}/$1 [R={{ $redirect->redirect_status }},L]
@endforeach
}
@endif

{{-- No `php.conf` here: this site runs no PHP, so there are no PHP settings to
     put in one. The old panel has no OpenLiteSpeed template for a node or
     static site at all — its OLS stack is PHP-only and MERN sites went to
     nginx — so this pair is this panel's own, following the same split.

     The snippet include is still load-bearing: it is where a customer's own
     directives live. A glob matching nothing is fine. --}}
include {{ $snippetDirs['conf'] }}/*.conf
include {{ $acmeAliasConf }}
