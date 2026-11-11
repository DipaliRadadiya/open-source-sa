{{-- The body of one VirtualHost, included by both the :80 and the :443
     block. Apache binds a VirtualHost to a single port, so unlike nginx
     there is no way to serve both from one block — and duplicating this
     is how the two copies drift until HTTPS quietly serves something
     different from HTTP. --}}
    {{-- Served from one shared directory rather than the site's own document
         root: node and proxy sites serve nothing from disk, so there would be
         nowhere for certbot to drop the token. Aliased ahead of everything else
         so a front-controller rewrite cannot swallow it — a WordPress site
         would otherwise answer with its 404 page, which Let's Encrypt reads as
         unauthorized and which costs one of five attempts an hour. --}}
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

    {{-- Addresses this site's fail2ban jail banned — for this site only, not
         at the firewall (frontend QA FB-K). --}}
    IncludeOptional {{ $siteRules }}/panel-fail2ban.conf
    DocumentRoot {{ $documentRoot }}
@if ($disabled)
    {{-- Disabled: every path answers 503 with the unavailable page. It was a
         200 — "up" to every monitor and crawler. The ACME alias is exempt so
         renewal keeps working; REDIRECT_STATUS keeps the error document's own
         internal request from being rewritten again. --}}
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
        {{-- The bot blocker and the firewall are rewrite rules in the site's
             conf folder (v7's files); this is the password, or everyone. --}}
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

    {{-- And dotfiles, which the rule above does not cover: DirectoryMatch
         matches directories, so `.git/` was refused while `.env` beside it was
         served as a plain text file. Filenames only, so `.well-known` is
         unaffected -- its own files are not dotfiles. --}}
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    {{-- The HTTPS VirtualHost logs to `-ssl` files, as v7 does; fail2ban
         and the Logs screen read both. --}}
    ErrorLog  {{ $logDir }}/error{{ ($tlsBlock ?? false) ? '-ssl' : '' }}.log
    CustomLog {{ $logDir }}/access{{ ($tlsBlock ?? false) ? '-ssl' : '' }}.log combined

    {{-- v7's per-site folder, last like in v7 (vhosts the same as v7,
         2026-11-08; {@see \App\Services\Server\WebServers\SiteConfFolder}).
         `*` as v7 had it: a glob skips the dot-file `.htpasswd`. --}}
    IncludeOptional {{ $siteConf }}/*
