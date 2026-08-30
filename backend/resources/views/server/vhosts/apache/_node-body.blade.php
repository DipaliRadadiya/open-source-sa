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

    ErrorLog  {{ $logDir }}/error.log
    CustomLog {{ $logDir }}/access.log combined

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

@if ($waf)
    Include {{ config('server.waf.apache_setenvif_path') }}
@foreach ($waf['exceptions'] as $exception)
    SetEnvIfNoCase Request_URI "{{ $exception }}" waf_exception
    SetEnvIfNoCase Query_String "{{ $exception }}" waf_exception
    SetEnvIfNoCase User-Agent "{{ $exception }}" waf_exception
@endforeach
@foreach ($waf['customRules'] as $rule)
    SetEnvIfNoCase Request_URI "{{ $rule }}" waf_custom
    SetEnvIfNoCase Query_String "{{ $rule }}" waf_custom
@endforeach
@endif
@if ($botBlock)
    SetEnvIfNoCase User-Agent "^({{ $botBlock }})" ai_bot_blocked
@endif
@if ($botBlock || $basicAuth || ($waf && $waf['mode'] === 'enforce'))
    {{-- A node app has no `<Directory>` of its own to attach any of these
         checks to — it serves nothing from disk — so all three are scoped
         by URL instead, with the ACME path excluded by the same regex the
         dotfile-deny rule below uses. One `RequireAll` so a blocked bot or
         WAF match fails here regardless of Basic Auth, the same as the
         php/static `<Directory>` blocks. --}}
    <LocationMatch "^/(?!\.well-known/acme-challenge/)">
        <RequireAll>
@if ($botBlock)
            Require not env ai_bot_blocked
@endif
@if ($waf && $waf['mode'] === 'enforce')
            <RequireAny>
                Require env waf_exception
                <RequireNone>
                    <RequireAny>
@if (in_array('query_string', $waf['categories'], true))
                        Require env waf_query
@endif
@if (in_array('request_uri', $waf['categories'], true))
                        Require env waf_uri
@endif
@if (in_array('user_agent', $waf['categories'], true))
                        Require env waf_agent
@endif
@if (in_array('referrer', $waf['categories'], true))
                        Require env waf_referer
@endif
@if (in_array('cookie', $waf['categories'], true))
                        Require env waf_cookie
@endif
@if (in_array('method', $waf['categories'], true))
                        Require env waf_method
@endif
@if ($waf['customRules'] !== [])
                        Require env waf_custom
@endif
                    </RequireAny>
                </RequireNone>
            </RequireAny>
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
@if ($waf && $waf['mode'] === 'detect')
@if (in_array('query_string', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} combined env=waf_query
@endif
@if (in_array('request_uri', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} combined env=waf_uri
@endif
@if (in_array('user_agent', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} combined env=waf_agent
@endif
@if (in_array('referrer', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} combined env=waf_referer
@endif
@if (in_array('cookie', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} combined env=waf_cookie
@endif
@if (in_array('method', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} combined env=waf_method
@endif
@if ($waf['customRules'] !== [])
    CustomLog {{ $waf['detectLogPath'] }} combined env=waf_custom
@endif
@endif
