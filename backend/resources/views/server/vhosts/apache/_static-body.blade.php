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

@if ($waf)
    {{-- The six category env vars (`waf_query`/`waf_uri`/`waf_agent`/
         `waf_referer`/`waf_cookie`/`waf_method`) are declared once,
         server-wide, and `Include`d here rather than duplicated into every
         WAF-enabled site's own vhost — see Waf8GManager::ensureSharedMaps().
         Setting an env var this site never checks below costs nothing. --}}
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
    {{-- `SetEnvIfNoCase` rather than mod_rewrite: it needs no `RewriteEngine`
         of its own, so it cannot conflict with a user's own rewrite rules in
         a `.htaccess` this vhost already allows (`AllowOverride All`). --}}
    SetEnvIfNoCase User-Agent "({{ $botBlock }})" ai_bot_blocked
@endif
    <Directory {{ $documentRoot }}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        {{-- `RequireAll` so a blocked bot or WAF match fails here regardless
             of Basic Auth — neither ever reaches the login prompt below. --}}
        <RequireAll>
@if ($botBlock)
            Require not env ai_bot_blocked
@endif
@if ($waf && $waf['mode'] === 'enforce')
            {{-- Grant access if (an exception matched) OR (no enabled
                 category/custom rule matched). A `RequireNone` cannot sit
                 directly in a `RequireAny` — Apache refuses the config with
                 "directive has no effect", since a negation alone never
                 grants — so it is paired with `Require all granted` inside
                 a `RequireAll`, which is how Apache spells "none matched".
                 Detect mode skips this entirely — see the CustomLog lines
                 below instead, which log without ever denying access. --}}
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

    ErrorLog  {{ $logDir }}/error.log
    CustomLog {{ $logDir }}/access.log combined
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
