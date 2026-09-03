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
{{-- Keep this hostname mapped to its own denied TLS vhost. Without vhssl OLS
     borrows the secure listener's first real certificate/vhost. --}}
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
  indexFiles              index.php, index.html
}

{{-- LSPHP over LSAPI, which is why there is no socket here to match the nginx
     and Apache templates: the version is chosen by which lsphp binary this
     handler points at.

     This used to say OpenLiteSpeed "cannot talk to PHP-FPM at all". That is
     false, and it was checked (2026-09-01) against the openlitespeed 1.9.2
     package rather than argued about: `fcgi` is a documented external-app type
     in OLS's own admin definitions (Product/WebServer/DTblDefBase.php lists
     lsapi|proxy|fcgi|fcgiauth|scgi|servlet|uwsgi), and the installer uses
     exactly that to run the panel itself on PHP-FPM.

     LSAPI is still the right choice *here*, for hosted sites — it is faster,
     it is what suEXEC per-site isolation is built around on this server, and
     LiteSpeed Cache needs it. But the reason is preference, not incapability,
     so do not let this comment talk you out of an fcgi handler where one is
     warranted. --}}
extprocessor lsphp{{ $lsphpVersion }} {
  type                    lsapi
  address                 uds://tmp/lshttpd/lsphp{{ $lsphpVersion }}-{{ $domain }}.sock
  maxConns                10
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               2
  path                    {{ $lsphpBinary }}
  extUser                 {{ $user }}
  extGroup                {{ $user }}
{{-- This site's own PHP settings — memory_limit, upload limits, open_basedir,
     disable_functions. On php-fpm they are php_admin_value lines in the pool
     file; there is no pool here, so LSPHP is pointed at an ini file of our own
     instead. See SitePhpIni for why an ini rather than phpIniOverride.

     The leading colon is not a typo and must not be "tidied": an empty entry
     means "also scan the directory PHP was compiled with", which is where
     every extension's ini lives. Without it this REPLACES that directory and
     the site loses mysqli, curl and opcache. --}}
  env                     PHP_INI_SCAN_DIR={{ $phpIniScanDir }}
  runOnStartUp            1
  memSoftLimit            2047M
  memHardLimit            2047M
  procSoftLimit           400
  procHardLimit           500
}

scripthandler {
  add                     lsapi:lsphp{{ $lsphpVersion }} php
}

{{-- The front-controller rewrite lives here rather than in .htaccess. OLS only
     reads .htaccess when Auto Load is on and needs a full restart after every
     change to one, which would turn a permalink edit into server downtime.

     This is Apache's mod_rewrite, which OpenLiteSpeed implements — not
     nginx's. Three consequences, each of which was wrong here before:

     - Rules are bare directives inside the block, the way the shipped
       Example vhconf.conf writes `RewriteFile`. The `rules <<<HEREDOC` form
       was a guess.
     - `RewriteRule . /index.php [L]` is the documented front controller.
       `^(.*)$ /index.php?$1` was a translation of nginx's `try_files`, and it
       hands the path to PHP as a *query string* — which WordPress does not
       read, since it works from REQUEST_URI.
     - **At vhost level OLS does not strip the leading slash** before matching,
       so the loop guard has to be `^/index\.php$`, not `^index\.php$`. Without
       it the rule rewrites index.php to itself. --}}
rewrite {
  enable                  1
@if (! $certificate)
  {{-- HTTP remains the application's working transport. Only stale/HSTS HTTPS
       requests are denied before they can reach this or another site. --}}
  RewriteCond %{HTTPS} =on
  RewriteRule ^ - [F,L]
@endif
@if ($botBlock)
  {{-- Checked first, ahead of HTTPS-force/redirects/the front controller —
       a blocked bot gets [F] (403) immediately. Apache mod_rewrite syntax,
       which OLS implements here, not nginx's. --}}
  RewriteCond %{HTTP_USER_AGENT} ({{ $botBlock }}) [NC]
  RewriteRule ^ - [F,L]
@endif
@if ($readsHtaccess)
  {{-- On for WordPress, because LiteSpeed Cache has no other way in: the
       plugin writes its cache rules to .htaccess and OpenLiteSpeed's cache
       module reads them from there. With this off, LSCache installs, activates,
       reports itself enabled — and caches nothing. That is the single reason
       most people choose OLS, so it must not be quietly disabled.

       The cost is real and accepted: OLS needs a graceful restart to pick up
       an .htaccess change, so a permalink or cache-setting change does not
       take effect until then. OLS only parses the rules it recognises, so the
       vhost rules below still cover everything else. --}}
  autoLoadHtaccess        1
@else
  {{-- Off everywhere else: the rewrite below is the whole rewrite, and an
       .htaccess a user drops in should not silently start costing restarts. --}}
  autoLoadHtaccess        0
@endif
@if ($forceHttps)
  {{-- Force HTTPS. The ACME exclusion is not optional: without it renewal
       stops working, and the redirect goes on pointing confidently at a
       certificate that has expired. --}}
  RewriteCond %{HTTPS} !=on
  RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
  RewriteRule ^/?(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
@endif
{{-- Redirect names first, before the front controller sees them: OLS routes
     them here as aliases, so without these they would serve the site instead
     of sending a 301. --}}
@foreach ($redirects as $redirect)
  RewriteCond %{HTTP_HOST} ^{{ preg_quote($redirect->domain, '/') }}$ [NC]
  RewriteRule ^/?(.*)$ {{ $redirect->redirect_to ?: $canonicalUrl }}/$1 [R={{ $redirect->redirect_status }},L]
@endforeach
  RewriteRule ^/index\.php$ - [L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule . /index.php [L]
}

{{-- Version control and dotfiles must never be served. A .git directory inside
     a web root is a full source disclosure.

     `exp:` is how OpenLiteSpeed spells a regex context — nginx's `~` is not
     valid here and fails the config test, which would have stopped every site
     from provisioning. The directories are named rather than matched with a
     `(?!well-known)` lookahead, both because .well-known must stay reachable
     for certificate issuance and because OLS's regex support is not
     documented as handling lookaheads. --}}
context exp:^/\.(git|svn|hg|bzr|env|panel) {
  allowBrowse             0
}
