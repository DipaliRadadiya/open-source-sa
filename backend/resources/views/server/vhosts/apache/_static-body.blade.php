{{-- Body of one VirtualHost, included by both the :80 and the :443 block. Apache binds a VirtualHost
     to a single port, so unlike nginx it cannot serve both from one block; duplicating this lets the
     two copies drift until HTTPS serves something different from HTTP. --}}
    {{-- Served from one shared directory rather than the site's own document root, and aliased ahead
         of everything else so a front-controller rewrite cannot swallow it: Let's Encrypt reads the 404 as unauthorized, which
         costs one of five attempts an hour. --}}
    Alias /.well-known/acme-challenge {{ $challengeRoot }}/.well-known/acme-challenge

    <Directory {{ $challengeRoot }}/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>
    ServerName {{ $serverNames[0] }}
@if (count($serverNames) > 1)
    ServerAlias {{ implode(' ', array_slice($serverNames, 1)) }}
@endif

    {{-- Addresses this site's fail2ban jail banned, for this site only, not at the firewall. --}}
    IncludeOptional {{ $siteRules }}/panel-fail2ban.conf
    DocumentRoot {{ $documentRoot }}
@if ($disabled)
    {{-- Disabled: every path answers 503 with the unavailable page, so monitors and crawlers do not see
         a 200. The ACME alias is exempt so renewal keeps working; REDIRECT_STATUS keeps the error
         document's own internal request from being rewritten again. --}}
    ErrorDocument 503 /index.html
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteCond %{ENV:REDIRECT_STATUS} ^$
    RewriteRule ^ - [R=503,L]
    Header always set Retry-After "3600"
@endif

    <Directory {{ $documentRoot }}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        {{-- The bot blocker and the firewall are rewrite rules in the site's conf folder; this is the
             password, or everyone. --}}
        <RequireAll>
@if ($basicAuth)
            AuthType Basic
            AuthName "Restricted"
            AuthUserFile {{ $basicAuth['htpasswdPath'] }}
            Require valid-user
@else
            Require all granted
@endif
        </RequireAll>
    </Directory>

    <DirectoryMatch "/\.(?!well-known)">
        Require all denied
    </DirectoryMatch>

    {{-- And dotfiles, which the rule above does not cover: DirectoryMatch matches directories, so
         `.git/` was refused while `.env` beside it was served. Filenames only, so `.well-known` is
         unaffected. --}}
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    {{-- The HTTPS VirtualHost logs to `-ssl` files; fail2ban and the Logs screen read both. --}}
    ErrorLog  {{ $logDir }}/error{{ ($tlsBlock ?? false) ? '-ssl' : '' }}.log
    CustomLog {{ $logDir }}/access{{ ($tlsBlock ?? false) ? '-ssl' : '' }}.log combined

    {{-- The site's per-site conf folder, last ({@see \App\Services\Server\WebServers\SiteConfFolder}).
         `*` because a glob skips the dot-file `.htpasswd`. --}}
    IncludeOptional {{ $siteConf }}/*
