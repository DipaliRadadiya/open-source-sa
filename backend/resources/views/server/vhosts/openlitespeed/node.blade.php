{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     OpenLiteSpeed proxies by declaring the backend as an external application of type `proxy` and
     pointing a context at it, not with a `proxy_pass`-style directive
     (docs.openlitespeed.org/config/reverseproxy). --}}
docRoot                   {{ $documentRoot }}
vhDomain                  {{ $serverNames[0] }}
@if (count($serverNames) > 1 || $redirects->isNotEmpty())
vhAliases                 {{ implode(', ', array_merge(array_slice($serverNames, 1), $redirects->pluck('domain')->all())) }}
@endif
enableGzip                1

{{-- The site's own `post_max_size`, in bytes. OpenLiteSpeed's default is effectively unlimited, so this
     never causes a 413; it makes a site behave the same on every web server. --}}
maxReqBodySize            {{ $maxBodySize }}

@if ($certificate)
{{-- OpenLiteSpeed keeps TLS on the vhost as well as the listener: the listener decides that 443 is
     answered, this decides which certificate is presented for this site. Without it a second site on
     the box is served the first one's certificate. --}}
vhssl {
  keyFile                 {{ $certificate->private_key_path }}
  certFile                {{ $certificate->certificate_path }}
  certChain               1
@if ($certificate->servesSeparateChain())
  CACertFile              {{ $certificate->chain_path }}
@endif
  {{-- TLS 1.2 as the floor. OLS spells the version set as a bitmask-style list; 1.0 and 1.1 are
       deprecated and fail PCI checks, and allowing them only helps browsers that stopped getting
       security updates years ago. --}}
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

{{-- The ACME challenge is served from one shared directory rather than the site's own document root,
     as its own context so the rewrite below cannot swallow it: Let's Encrypt reads the 404 a WordPress
     site would answer as unauthorized, which costs one of five attempts an hour. --}}
context /.well-known/acme-challenge {
  location                {{ $challengeRoot }}/.well-known/acme-challenge
  allowBrowse             1
  addDefaultCharset       off
}

{{-- OLS rotates these two logs itself (ApplicationLogRotation leaves them alone), so retention is set
     here. Without keepDays OLS keeps every rotated error log. --}}
errorlog $VH_ROOT/logs/error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
  keepDays                30
}

accesslog $VH_ROOT/logs/access.log {
  useServer               0
@if ($waf)
  {{-- OLS allows one access log per site, so the firewall marks its lines here (`waf=1`) instead of
       writing waf-detect.log; the log screen filters on it. Logged in both modes, with the rule that
       matched; a 403 is a block, anything else a detection. Combined format otherwise, as OLS writes by
       default. --}}
  logFormat               %h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" waf=%{waf_would}e reason=%{waf_block}e
@endif
  rollingSize             10M
  keepDays                30
  compressArchive         1
}

{{-- The backend, as an external application. `address` carries the scheme
     here, unlike the websocket block below. --}}
extprocessor {{ $appName }} {
  type                    proxy
  address                 http://127.0.0.1:{{ $appPort }}
  maxConns                100
  initTimeout             60
  retryTimeout            0
  respBuffer              0
}

{{-- Everything goes to the app. A Node application owns its own routing, so
     there is no static context ahead of this. --}}
context / {
  type                    proxy
  handler                 {{ $appName }}
  addDefaultCharset       off
@if ($basicAuth)
  realm                   {{ $basicAuth['realm'] }}
  authName                Restricted
@endif
}

@if ($basicAuth)
{{-- Basic auth: no `userNameSeparator` line (OLS rejects it: "Not support [usernameseparator :]"), and
     `authName` unquoted because OLS adds the quotes. The ACME context above is more specific and
     matches first, so its challenge path stays reachable without a password. --}}
realm {{ $basicAuth['realm'] }} {
  userDB {
    location               {{ $basicAuth['htpasswdPath'] }}
  }
}
@endif

{{-- WebSockets are a separate block in OpenLiteSpeed: traffic carrying the upgrade request is matched
     here and everything else falls through to the context above. The address has **no scheme** (OLS
     wants host:port); WSS backends are unsupported, which is fine because the client's TLS terminates
     here. --}}
websocket / {
  address                 127.0.0.1:{{ $appPort }}
}

{{-- A rewrite block only when there is something to rewrite: a redirect, HTTPS-force, or an active bot
     policy. OLS routes redirect names here as aliases, so they must be sent on explicitly or they
     would serve the site under a second name. --}}
{{-- Always present: the site's fail2ban jail bans an address for this site by writing a rule into the
     file included first below, and the jail can be switched on long after this file was written. --}}
rewrite {
  enable                  1
  include {{ $siteRules }}/panel-fail2ban*.conf
  {{-- The site's rewrite rules (its bot blocker and 8G firewall live here). --}}
  include {{ $siteConf }}/rewrites/*.conf
@if (! $certificate)
  RewriteCond %{HTTPS} =on
  RewriteRule ^ - [F,L]
@endif
{{-- Redirect names before HTTPS-force: the other way round, a plain-HTTP request for a redirect name
     was first sent to https://<that name>, which the certificate usually does not cover, so the
     redirect never fired. --}}
@foreach ($redirects as $redirect)
  RewriteCond %{HTTP_HOST} ^{{ preg_quote($redirect->domain, '/') }}$ [NC]
  RewriteRule ^/?(.*)$ {{ $redirect->redirectTarget() ?: $canonicalUrl }}/$1 [R={{ $redirect->redirect_status }},L]
@endforeach
@if ($forceHttps)
  {{-- Force HTTPS. The ACME exclusion is not optional: without it renewal stops and the redirect points
       at an expired certificate. --}}
@foreach ($uncoveredNames as $name)
  {{-- Not on the certificate: that name over https is a TLS error, so send it to the primary. ACME stays
       excluded so the certificate can be reissued to include this name. --}}
  RewriteCond %{HTTPS} !=on
  RewriteCond %{HTTP_HOST} ^{{ preg_quote($name, '/') }}$ [NC]
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteRule ^/?(.*)$ https://{{ $serverNames[0] }}/$1 [R=301,L]
@endforeach
  RewriteCond %{HTTPS} !=on
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteRule ^/?(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
@endif
}


include {{ $siteConf }}/*.conf
