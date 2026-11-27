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
    DocumentRoot {{ $documentRoot }}

    {{-- The site's own `post_max_size`, in bytes. Apache's default is unlimited, so this never causes a
         413 here; it makes a site behave the same on all three web servers. --}}
    LimitRequestBody {{ $maxBodySize }}

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
@if ($basicAuth['serverOnly'])
            {{-- The application's own requests to itself (WordPress's wp-cron.php) need no password, and only
                 those: exact path AND one of this server's own addresses. Still subject to the bot blocker and
                 the firewall, which run as rewrite rules before this is reached. --}}
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

    {{-- mod_proxy_fcgi drops the Authorization header, so an app that does not set it in its own
         .htaccess (API tokens, JWT) would never see it. --}}
    SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1

    {{-- Browser caching: same types and times as v7. --}}
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

    {{-- And dotfiles, which the rule above does not cover: DirectoryMatch matches directories, so
         `.git/` was refused while `.env` beside it was served. Filenames only, so `.well-known` is
         unaffected. --}}
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    {{-- The HTTPS VirtualHost logs to `-ssl` files; fail2ban and the Logs screen read both. --}}
    ErrorLog  {{ $logDir }}/error{{ ($tlsBlock ?? false) ? '-ssl' : '' }}.log
    CustomLog {{ $logDir }}/access{{ ($tlsBlock ?? false) ? '-ssl' : '' }}.log combined

    {{-- Extra rules an addon keeps for this site (the WordPress toolkit's xmlrpc and uploads-PHP
         blocks). Included, not rendered, so a domain, certificate or HTTPS change that rewrites this file
         leaves them in place. `IncludeOptional` because the directory is usually empty. Last, so a
         `<Files>`/`<LocationMatch>` deny here is merged after the `<Directory>` grant above and wins. --}}
    IncludeOptional {{ $siteRules }}/*.conf

    {{-- The site's per-site conf folder, last ({@see \App\Services\Server\WebServers\SiteConfFolder}).
         `*` because a glob skips the dot-file `.htpasswd`. --}}
    IncludeOptional {{ $siteConf }}/*
