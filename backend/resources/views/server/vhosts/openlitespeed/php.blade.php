{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
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
{{-- Keep this hostname mapped to its own denied TLS vhost. Without vhssl OLS
     borrows the secure listener's first real certificate/vhost. --}}
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

@if ($basicAuth)
{{-- Basic auth: no `userNameSeparator` line (OLS rejects it: "Not support [usernameseparator :]"), and
     `authName` unquoted because OLS adds the quotes. `context /` is declared explicitly only when
     protection is on, so an unprotected site's config is unchanged. The ACME context above is more
     specific and matches first, so it is never affected. --}}
@foreach ($basicAuth['serverOnly']['paths'] ?? [] as $path)
{{-- The application's own request to itself (WordPress's wp-cron.php): no realm, so no password, but
     only from this server's own addresses; anyone else gets 403. A regex (`exp:`) context, because a
     plain `context /wp-cron.php` inherits the realm of `context /` and would still ask the server
     itself for the password (401). --}}
context exp:^{{ preg_quote($path, '#') }}$ {
  location                {{ $documentRoot }}{{ $path }}
  allowBrowse             1
  accessControl  {
    allow                 {{ implode(', ', $basicAuth['serverOnly']['addresses']) }}
    deny                  ALL
  }
}

@endforeach
context / {
  location                {{ $documentRoot }}
  allowBrowse             1
  realm                   {{ $basicAuth['realm'] }}
  authName                Restricted
}

realm {{ $basicAuth['realm'] }} {
  userDB {
    location               {{ $basicAuth['htpasswdPath'] }}
  }
}
@endif

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

{{-- Browser caching for static files. --}}
expires {
  enableExpires           1
  expiresByType           image/*=A31536000,text/css=A31536000,application/x-javascript=A31536000,application/javascript=A31536000,font/*=A31536000,application/x-font-ttf=A31536000
}

index {
  useServer               0
  indexFiles              index.php, index.html
}

{{-- LSPHP over LSAPI, which is why there is no socket here as in the nginx and Apache templates: the
     PHP version is chosen by which lsphp binary this handler points at.
     LSAPI is the choice for hosted sites (faster, what suEXEC per-site isolation is built around here,
     and LiteSpeed Cache needs it), not a limitation: OLS can also talk to PHP-FPM through the `fcgi`
     external-app type, which the installer uses for the panel itself. Use an fcgi handler where one is
     warranted. --}}
{{-- Named for this site, never just `lsphp84`: OpenLiteSpeed keeps one
     external app per name, so a name every vhost shares runs every site's PHP
     as whichever site was loaded first. See `processorName` in OlsDriver. --}}
extprocessor {{ $processorName }} {
  type                    lsapi
  address                 uds://tmp/lshttpd/lsphp{{ $lsphpVersion }}-{{ $socketName }}.sock
  maxConns                {{ $lsapiChildren }}
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               2
  path                    {{ $lsphpBinary }}
{{-- The site's own identity, and the whole isolation boundary on this stack. Without extUser OLS runs
     the process as `nobody` and every site on the box can read the others' `.env`. `OlsDriver` refuses
     to render this template without a username, and `panel:doctor` asserts no lsphp runs as nobody or
     root. --}}
  extUser                 {{ $user }}
  extGroup                {{ $user }}
{{-- This site's own PHP settings (memory_limit, upload limits, open_basedir, disable_functions). On
     php-fpm they are php_admin_value lines in the pool file; here LSPHP is pointed at an ini of our own
     (see SitePhpIni).
     The leading colon is not a typo and must not be tidied: an empty entry means "also scan the
     directory PHP was compiled with", where every extension's ini lives. Without it that directory is
     REPLACED and the site loses mysqli, curl and opcache. --}}
  env                     PHP_INI_SCAN_DIR={{ $phpIniScanDir }}
{{-- LSAPI must be told the pool size: `maxConns` is what OpenLiteSpeed will open, PHP_LSAPI_CHILDREN is
     how many workers LSPHP forks. Setting one without the other leaves the two halves disagreeing, so
     both are rendered from one value. --}}
  env                     PHP_LSAPI_CHILDREN={{ $lsapiChildren }}
{{-- Recycle a worker after this many requests: an LSPHP child otherwise lives until the server
     restarts, so any per-request leak accumulates. The php-fpm equivalent is `pm.max_requests`. --}}
  env                     PHP_LSAPI_MAX_REQUESTS={{ $lsapiMaxRequests }}
  runOnStartUp            1
  memSoftLimit            2047M
  memHardLimit            2047M
  procSoftLimit           400
  procHardLimit           500
}

scripthandler {
  add                     lsapi:{{ $processorName }} php
}

{{-- The front-controller rewrite lives here rather than in .htaccess: OLS only reads .htaccess when
     Auto Load is on and needs a full restart after every change to one, which would turn a permalink
     edit into server downtime.
     This is Apache's mod_rewrite as OpenLiteSpeed implements it, not nginx's:
     - Rules are bare directives inside the block, as the shipped Example vhconf.conf writes
       `RewriteFile` (not a `rules <<<HEREDOC` form).
     - `RewriteRule . /index.php [L]` is the documented front controller; `^(.*)$ /index.php?$1` would
       hand the path to PHP as a query string, which WordPress does not read (it uses REQUEST_URI).
     - At vhost level OLS does not strip the leading slash before matching, so the loop guard has to be
       `^/index\.php$`, not `^index\.php$`; otherwise the rule rewrites index.php to itself. --}}
rewrite {
  enable                  1
  {{-- Extra rules an addon keeps for this site (the WordPress toolkit's xmlrpc and uploads-PHP blocks),
       as rewrite rules, the only syntax OLS reads here. Included, not rendered, so a domain, certificate
       or HTTPS change that rewrites this file leaves them in place. First, so a block answers [F] before
       anything else is considered. --}}
  include {{ $siteRules }}/*.conf
  {{-- The site's rewrite rules (its bot blocker and 8G firewall live here). --}}
  include {{ $siteConf }}/rewrites/*.conf
@if (! $certificate)
  {{-- HTTP remains the application's working transport. Only stale/HSTS HTTPS
       requests are denied before they can reach this or another site. --}}
  RewriteCond %{HTTPS} =on
  RewriteRule ^ - [F,L]
@endif
@if ($readsHtaccess)
  {{-- On for WordPress, because LiteSpeed Cache has no other way in: the plugin writes its cache rules to
       .htaccess and OpenLiteSpeed's cache module reads them from there. With this off, LSCache installs,
       reports itself enabled, and caches nothing.
       Accepted cost: OLS needs a graceful restart to pick up an .htaccess change, so a permalink or
       cache-setting change waits for it. OLS only parses the rules it recognises, so the vhost rules
       below still cover everything else. --}}
  autoLoadHtaccess        1
@else
  {{-- Off everywhere else: the rewrite below is the whole rewrite, and an .htaccess a user drops in
       should not silently start costing restarts. --}}
  autoLoadHtaccess        0
@endif
{{-- Redirect names first, before HTTPS-force and the front controller. OLS routes them here as
     aliases, so without these they would serve the site instead of sending a 301. The other way round,
     a plain-HTTP request for a redirect name was first sent to https://<that name>, which the
     certificate usually does not cover, so the redirect never fired. --}}
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
@foreach ($wellKnown['redirects'] as $name => $target)
  {{-- Service discovery the application's .htaccess does on Apache
       (Nextcloud CalDAV/CardDAV); this vhost does not read that file. --}}
  RewriteRule ^/\.well-known/{{ preg_quote($name, '/') }}$ {{ $target }} [R=301,L]
@endforeach
@if ($wellKnown['fallback'])
  RewriteCond %{REQUEST_URI} !^/\.well-known/(acme-challenge|pki-validation)/
  RewriteRule ^/\.well-known/ {{ $wellKnown['fallback'] }} [L]
@endif
@foreach ($subdirectoryFrontControllers as $front)
  {{-- A directory with its own front controller (PrestaShop's back office),
       which Apache routes with the `.htaccess` inside it. Before the shop's
       own front controller, which would otherwise take these and 404. --}}
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^/({{ $front['directory'] }})/ /$1/{{ $front['script'] }} [L]
@endforeach
  RewriteRule ^/index\.php$ - [L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule . /index.php [L]
}

{{-- Version control and dotfiles must never be served. A .git directory inside a web root is a full
     source disclosure.
     `exp:` is how OpenLiteSpeed spells a regex context; nginx's `~` is not valid here and fails the
     config test. Directories are named rather than matched with a `(?!well-known)` lookahead,
     because .well-known must stay reachable for certificate issuance and OLS's regex support is not
     documented as handling lookaheads.
     Not anchored to the top of the site: `^/\.` refused /.env but served /sub/.env with 200, where
     nginx and Apache refuse a dotfile at any depth. --}}
context exp:/\.(git|svn|hg|bzr|env|panel) {
  allowBrowse             0
}
@foreach ($deniedPaths as $pattern)

{{-- The application ships this as an Apache `.htaccess` rule, which this vhost does not read for it:
     without it logs, sessions and source under the web root are downloadable. --}}
context exp:{{ $pattern }} {
  allowBrowse             0
}
@endforeach


include {{ $siteConf }}/*.conf
