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

    ErrorLog  ${APACHE_LOG_DIR}/{{ $domain }}.error.log
    CustomLog ${APACHE_LOG_DIR}/{{ $domain }}.access.log combined

    {{-- Off would rewrite the Host header to 127.0.0.1, so the app builds
         redirects and absolute URLs pointing at the loopback address. --}}
    ProxyPreserveHost On
    ProxyRequests Off

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
    {{-- A node app has no `<Directory>` of its own to attach this to — it
         serves nothing from disk — so this is scoped by URL instead, with
         the ACME path excluded by the same regex the dotfile-deny rule below
         uses, rather than a second `<Location>` turning it back off. --}}
    <LocationMatch "^/(?!\.well-known/acme-challenge/)">
        AuthType Basic
        AuthName "Restricted"
        AuthUserFile {{ $basicAuth['htpasswdPath'] }}
        Require valid-user
    </LocationMatch>
@endif

    {{-- A .git directory inside a served tree is a full source disclosure. --}}
    <DirectoryMatch "/\.(?!well-known)">
        Require all denied
    </DirectoryMatch>
