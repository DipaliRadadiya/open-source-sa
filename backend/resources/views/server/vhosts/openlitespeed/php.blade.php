{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
docRoot                   {{ $documentRoot }}
vhDomain                  {{ $serverNames[0] }}
@if (count($serverNames) > 1 || $redirects->isNotEmpty())
vhAliases                 {{ implode(', ', array_merge(array_slice($serverNames, 1), $redirects->pluck('domain')->all())) }}
@endif
enableGzip                1

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

{{-- LSPHP over LSAPI. OpenLiteSpeed cannot talk to PHP-FPM at all, so there is
     no socket here to match the nginx and Apache templates — the version is
     chosen by which lsphp binary this handler points at. --}}
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
{{-- Redirect names first, before the front controller sees them: OLS routes
     them here as aliases, so without these they would serve the site instead
     of sending a 301. --}}
@foreach ($redirects as $redirect)
  RewriteCond %{HTTP_HOST} ^{{ preg_quote($redirect->domain, '/') }}$ [NC]
  RewriteRule ^/?(.*)$ {{ $redirect->redirect_to ?: 'https://'.$domain }}/$1 [R={{ $redirect->redirect_status }},L]
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
context exp:^/\.(git|svn|hg|bzr|env) {
  allowBrowse             0
}
