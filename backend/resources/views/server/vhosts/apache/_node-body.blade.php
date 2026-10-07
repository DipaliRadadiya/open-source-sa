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

    ErrorLog  {{ $logDir }}/error.log
    CustomLog {{ $logDir }}/access.log combined

    {{-- The site's own `post_max_size`, in bytes. Apache's default is
         unlimited, so this was never the source of a 413 here — it is set so
         that a site behaves the same on all three web servers rather than
         depending on which one the box happens to run. --}}
    LimitRequestBody {{ $maxBodySize }}

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
    {{-- The path only (bug #82): matched against the query string or the
         user agent, an exception was a password anyone could type. --}}
    SetEnvIfExpr "%{REQUEST_URI} =~ m#{!! $exception !!}#i" waf_exception
@endforeach
@foreach ($waf['customRules'] as $rule)
    SetEnvIfExpr "%{REQUEST_URI} =~ m#{!! $rule !!}#i || %{QUERY_STRING} =~ m#{!! $rule !!}#i" waf_custom
@endforeach
@endif
@if ($botBlock)
    SetEnvIfNoCase User-Agent "({{ $botBlock }})" ai_bot_blocked
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
                <RequireAll>
                    Require all granted
                    <RequireNone>
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
                    </RequireNone>
                </RequireAll>
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
@if ($waf)
    {{-- Both modes (bug #84): blocking used to leave no record of what it
         blocked. One line per active category/custom rule — a request
         matching two logs twice, harmless for a log nobody is asked to
         dedupe. `expr=`, not `env=`: an excepted request was never blocked
         and must not be logged as if it were. The format is combined plus
         which rule matched and what was done. --}}
@if (in_array('query_string', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=query_string action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}" "expr=-n reqenv('waf_query') && -z reqenv('waf_exception')"
@endif
@if (in_array('request_uri', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=request_uri action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}" "expr=-n reqenv('waf_uri') && -z reqenv('waf_exception')"
@endif
@if (in_array('user_agent', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=user_agent action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}" "expr=-n reqenv('waf_agent') && -z reqenv('waf_exception')"
@endif
@if (in_array('referrer', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=referrer action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}" "expr=-n reqenv('waf_referer') && -z reqenv('waf_exception')"
@endif
@if (in_array('cookie', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=cookie action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}" "expr=-n reqenv('waf_cookie') && -z reqenv('waf_exception')"
@endif
@if (in_array('method', $waf['categories'], true))
    CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=method action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}" "expr=-n reqenv('waf_method') && -z reqenv('waf_exception')"
@endif
@if ($waf['customRules'] !== [])
    CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=custom_rule action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}" "expr=-n reqenv('waf_custom') && -z reqenv('waf_exception')"
@endif
@endif
