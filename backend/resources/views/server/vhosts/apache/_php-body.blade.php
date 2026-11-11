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
    DocumentRoot {{ $documentRoot }}

    {{-- The site's own `post_max_size`, in bytes. Apache's default is
         unlimited, so this was never the source of a 413 here — it is set so
         that a site behaves the same on all three web servers rather than
         depending on which one the box happens to run. --}}
    LimitRequestBody {{ $maxBodySize }}

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
@if ($basicAuth['serverOnly'])
            {{-- The application's own requests to itself (WordPress's
                 wp-cron.php, bug #75) need no password, and only those:
                 exact path AND one of this server's own addresses. Still
                 subject to the bot blocker and the firewall, which run as
                 rewrite rules before this is reached. --}}
            <RequireAny>
                Require valid-user
                <RequireAll>
                    Require expr "%{REQUEST_URI} =~ m#^({{ collect($basicAuth['serverOnly']['paths'])->map(fn ($p) => preg_quote($p, '#'))->implode('|') }})$#"
                    Require ip {{ implode(' ', $basicAuth['serverOnly']['addresses']) }}
                </RequireAll>
            </RequireAny>
@else
            Require valid-user
@endif
@else
            Require all granted
@endif
        </RequireAll>
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{{ $phpSocket }}|fcgi://localhost"
    </FilesMatch>

    {{-- v7 passed the Authorization header on to PHP; mod_proxy_fcgi drops
         it, and an app that does not set it in its own .htaccess (API tokens,
         JWT) then never sees it. --}}
    SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1

    {{-- v7's browser caching, same types and times. --}}
    <IfModule mod_expires.c>
        ExpiresActive on
        ExpiresDefault "access plus 2 days"
        ExpiresByType image/jpg "access plus 1 month"
        ExpiresByType image/gif "access plus 1 month"
        ExpiresByType image/jpeg "access plus 1 month"
        ExpiresByType image/png "access plus 1 month"
        ExpiresByType text/css "access plus 1 month"
        ExpiresByType text/javascript "access plus 1 month"
        ExpiresByType application/javascript "access plus 1 month"
        ExpiresByType application/x-shockwave-flash "access plus 1 month"
        ExpiresByType image/ico "access plus 1 month"
        ExpiresByType image/x-icon "access plus 1 month"
        ExpiresByType text/html "access plus 600 seconds"
    </IfModule>

    {{-- A .git directory inside a web root is a full source disclosure. --}}
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

    {{-- Extra rules an addon keeps for this site (the WordPress toolkit's
         xmlrpc and uploads-PHP blocks). Included, not rendered, so a domain,
         certificate or HTTPS change that rewrites this file leaves them in
         place. `IncludeOptional` because the directory is usually empty.
         Last, so a `<Files>`/`<LocationMatch>` deny here is merged after the
         `<Directory>` grant above and wins. --}}
    IncludeOptional {{ $siteRules }}/*.conf

    {{-- v7's per-site folder, last like in v7 (vhosts the same as v7,
         2026-11-08; {@see \App\Services\Server\WebServers\SiteConfFolder}).
         `*` as v7 had it: a glob skips the dot-file `.htpasswd`. --}}
    IncludeOptional {{ $siteConf }}/*
