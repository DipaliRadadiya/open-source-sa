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
@else
vhssl {
  keyFile                 {{ $tlsFallback['private_key'] }}
  certFile                {{ $tlsFallback['certificate'] }}
  certChain               0
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
{{-- Verified against OpenLiteSpeed 1.9.2 on a real server (2026-09-23):
     401 without a password, 200 with it, bcrypt hashes accepted, and the
     ACME challenge path still reachable without one. The earlier untested block carried a
     `userNameSeparator` line OLS rejects outright ("Not support
     [usernameseparator :]"), so every attempt to switch protection on
     failed its config test. `authName` unquoted — OLS adds the quotes,
     and quoting it here gave a prompt reading ""Restricted"".
     `context /` is declared
     explicitly only when protection is on, so an unprotected site's config
     is byte-for-byte what it always was — the ACME context above is more
     specific and matches first, so it is never affected either way. --}}
context / {
  location                {{ $documentRoot }}
  allowBrowse             1
  realm                   {{ $basicAuth['realm'] }}
  authName                Restricted
@if ($disabled)
  extraHeaders            <<<END_extraHeaders
set Retry-After 3600
  END_extraHeaders
@endif
}

realm {{ $basicAuth['realm'] }} {
  userDB {
    location               {{ $basicAuth['htpasswdPath'] }}
  }
}
@elseif ($disabled)
{{-- Disabled: the 503 carries Retry-After, as on nginx and Apache — the
     standard hint for monitors and crawlers to come back later. --}}
context / {
  location                {{ $documentRoot }}
  allowBrowse             1
  extraHeaders            <<<END_extraHeaders
set Retry-After 3600
  END_extraHeaders
}
@endif

errorlog $VH_ROOT/logs/error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog $VH_ROOT/logs/access.log {
  useServer               0
@if ($waf && $waf['mode'] === 'detect')
  {{-- OLS allows one access log per site, so detect mode marks the lines
       here (`waf=1`) instead of writing waf-detect.log; the log screen
       filters on it. Combined format otherwise, as OLS writes by default. --}}
  logFormat               %h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" waf=%{waf_would}e
@endif
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
context exp:/\.(git|svn|hg|bzr|env|panel) {
  allowBrowse             0
}

{{-- A rewrite block only when there is something to rewrite — a redirect,
     HTTPS-force, or an active bot policy. OLS routes redirect names here as
     aliases, so they must be sent on explicitly or they would serve the
     site under a second name. --}}
@if ($disabled)
{{-- Disabled: served as 503 with the unavailable page. It was a 200 — "up"
     to every monitor and crawler. --}}
errorpage 503 {
  url                     /index.html
}

@endif
@if (! $certificate || $redirects->isNotEmpty() || $forceHttps || $botBlock || $disabled || $waf)
rewrite {
  enable                  1
@if (! $certificate)
  RewriteCond %{HTTPS} =on
  RewriteRule ^ - [F,L]
@endif
@if ($waf)
  {{-- 8G Firewall, before the bot block and everything else. Each category
       and custom rule marks the request (`waf_block`); an exception marks it
       too (`waf_exception`); one rule at the end blocks — or, in detect mode,
       only marks it for the access log — when it is blocked and not excepted.
       Conditions are printed raw: they are already escaped for this syntax
       (OlsDriver::wafPattern, and the 8G file's own patterns). --}}
@foreach ($waf['exceptions'] as $exception)
  {{-- The path only (bug #82): matched against the query string or the
       user agent, an exception was a password anyone could type. --}}
  RewriteCond %{REQUEST_URI} {!! $exception !!} [NC]
  RewriteRule ^ - [E=waf_exception:1]
@endforeach
@foreach ($wafRules as $category => $conditions)
@foreach ($conditions as [$variable, $pattern])
  RewriteCond {!! '%{'.$variable.'}' !!} {!! $pattern !!} [NC{{ $loop->last ? '' : ',OR' }}]
@endforeach
  RewriteRule ^ - [E=waf_block:1]
@endforeach
@foreach ($waf['customRules'] as $rule)
  RewriteCond %{REQUEST_URI} {!! $rule !!} [NC,OR]
  RewriteCond %{QUERY_STRING} {!! $rule !!} [NC]
  RewriteRule ^ - [E=waf_block:1]
@endforeach
  RewriteCond %{ENV:waf_exception} !=1
  RewriteCond %{ENV:waf_block} =1
@if ($waf['mode'] === 'enforce')
  RewriteRule ^ - [F,L]
@else
  RewriteRule ^ - [E=waf_would:1]
@endif
@endif
@if ($botBlock)
  {{-- Checked first — a blocked bot gets [F] (403) immediately, ahead of
       HTTPS-force or any redirect. Apache mod_rewrite syntax, which OLS
       implements here, not nginx's. --}}
  RewriteCond %{HTTP_USER_AGENT} ({{ $botBlock }}) [NC]
  RewriteRule ^ - [F,L]
@endif
{{-- Redirect names before HTTPS-force. The other way round, a plain-HTTP
     request for a redirect name was first sent to https://<that name> — a
     name the certificate usually does not cover — so the visitor got a TLS
     error and the redirect never fired. --}}
@foreach ($redirects as $redirect)
  RewriteCond %{HTTP_HOST} ^{{ preg_quote($redirect->domain, '/') }}$ [NC]
  RewriteRule ^/?(.*)$ {{ $redirect->redirect_to ?: $canonicalUrl }}/$1 [R={{ $redirect->redirect_status }},L]
@endforeach
@if ($forceHttps)
  {{-- Force HTTPS. The ACME exclusion is not optional: without it renewal
       stops working, and the redirect goes on pointing confidently at a
       certificate that has expired. --}}
@foreach ($uncoveredNames as $name)
  {{-- Not on the certificate: https://{{ $name }} is a TLS error, so send it
       to the primary. ACME stays excluded so the certificate can still be
       reissued to include this name. --}}
  RewriteCond %{HTTPS} !=on
  RewriteCond %{HTTP_HOST} ^{{ preg_quote($name, '/') }}$ [NC]
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteRule ^/?(.*)$ https://{{ $serverNames[0] }}/$1 [R=301,L]
@endforeach
  RewriteCond %{HTTPS} !=on
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteRule ^/?(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
@endif
@if ($disabled)
  {{-- Every path but the ACME challenge (renewal) and the page itself. --}}
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteCond %{REQUEST_URI} !^/index\.html$
  RewriteRule ^ - [R=503,L]
@endif
}
@endif
