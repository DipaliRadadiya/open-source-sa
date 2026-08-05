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

@if ($botBlock)
    {{-- `SetEnvIfNoCase` rather than mod_rewrite: it needs no `RewriteEngine`
         of its own, so it cannot conflict with a user's own rewrite rules in
         a `.htaccess` this vhost already allows (`AllowOverride All`). --}}
    SetEnvIfNoCase User-Agent "^({{ $botBlock }})" ai_bot_blocked
@endif
    <Directory {{ $documentRoot }}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        {{-- `RequireAll` so a blocked bot fails here regardless of Basic
             Auth — it never reaches the login prompt below. --}}
        <RequireAll>
@if ($botBlock)
            Require not env ai_bot_blocked
@endif
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

    ErrorLog  ${APACHE_LOG_DIR}/{{ $domain }}.error.log
    CustomLog ${APACHE_LOG_DIR}/{{ $domain }}.access.log combined
