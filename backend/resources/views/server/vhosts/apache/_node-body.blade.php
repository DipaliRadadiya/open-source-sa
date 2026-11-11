{{-- The body of one VirtualHost, included by both the :80 and the :443
     block. Apache binds a VirtualHost to a single port, so unlike nginx
     there is no way to serve both from one block — and duplicating this
     is how the two copies drift until HTTPS quietly proxies somewhere
     different from HTTP. --}}
    {{-- Served from one shared directory rather than the site's own document
         root: node and proxy sites serve nothing from disk, so there would be
         nowhere for certbot to drop the token. Aliased ahead of everything else
         so the proxy cannot swallow it — otherwise the token is forwarded to
         the Node app, which answers 404, which Let's Encrypt reads as
         unauthorized and which costs one of five attempts an hour. --}}
    ProxyPass /.well-known/acme-challenge !
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

    {{-- The HTTPS VirtualHost logs to `-ssl` files, as v7 does; fail2ban
         and the Logs screen read both. --}}
    ErrorLog  {{ $logDir }}/error{{ ($tlsBlock ?? false) ? '-ssl' : '' }}.log
    CustomLog {{ $logDir }}/access{{ ($tlsBlock ?? false) ? '-ssl' : '' }}.log combined

    {{-- The site's own `post_max_size`, in bytes. Apache's default is
         unlimited, so this was never the source of a 413 here — it is set so
         that a site behaves the same on all three web servers rather than
         depending on which one the box happens to run. --}}
    LimitRequestBody {{ $maxBodySize }}

    {{-- Off would rewrite the Host header to 127.0.0.1, so the app builds
         redirects and absolute URLs pointing at the loopback address. --}}
    ProxyPreserveHost On
    ProxyRequests Off

    {{-- v7's per-site folder ({@see \App\Services\Server\WebServers\SiteConfFolder}):
         the bot blocker, the 8G firewall and force HTTPS are rewrite rules
         there, so it loads before the proxy rewrite below, or a WebSocket
         upgrade would skip them. `*` as v7 had it: a glob skips `.htpasswd`. --}}
    IncludeOptional {{ $siteConf }}/*

    {{-- WebSocket upgrades first: ProxyPass on / would otherwise swallow them
         as ordinary HTTP, and the handshake would never complete. Order is
         the whole trick here. --}}
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule ^/?(.*) ws://127.0.0.1:{{ $appPort }}/$1 [P,L]

    ProxyPass        / http://127.0.0.1:{{ $appPort }}/ connectiontimeout=10 timeout=60
    ProxyPassReverse / http://127.0.0.1:{{ $appPort }}/

    RequestHeader set X-Forwarded-Proto "%{REQUEST_SCHEME}s"

@if ($basicAuth)
    {{-- A node app has no `<Directory>` of its own to attach the password
         to — it serves nothing from disk — so it is scoped by URL instead,
         with the ACME path excluded by the same regex the dotfile-deny rule
         below uses. The bot blocker and the firewall are rewrite rules in the
         site's conf folder (v7's files), loaded before the proxy rules. --}}
    <LocationMatch "^/(?!\.well-known/acme-challenge/)">
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
    </LocationMatch>
@endif

    {{-- A .git directory inside a served tree is a full source disclosure. --}}
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

