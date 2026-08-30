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

@if ($waf)
    {{-- The six category env vars (`waf_query`/`waf_uri`/`waf_agent`/
         `waf_referer`/`waf_cookie`/`waf_method`) are declared once,
         server-wide, and `Include`d here rather than duplicated into every
         WAF-enabled site's own vhost — see Waf8GManager::ensureSharedMaps().
         Setting an env var this site never checks below costs nothing. --}}
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
    {{-- `SetEnvIfNoCase` rather than mod_rewrite: it needs no `RewriteEngine`
         of its own, so it cannot conflict with a user's own rewrite rules in
         a `.htaccess` this vhost already allows (`AllowOverride All`). --}}
    SetEnvIfNoCase User-Agent "^({{ $botBlock }})" ai_bot_blocked
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
                 category/custom rule matched) — the two-level RequireAny
                 wrapping a RequireNone is Apache's way of expressing
                 "blocked AND NOT excepted" without a boolean AND operator.
                 Detect mode skips this entirely — see the CustomLog lines
                 below instead, which log without ever denying access. --}}
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
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{{ $phpSocket }}|fcgi://localhost"
    </FilesMatch>

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

    ErrorLog  {{ $logDir }}/error.log
    CustomLog {{ $logDir }}/access.log combined
@if ($waf && $waf['mode'] === 'detect')
    {{-- One line per active category/custom rule rather than a single
         combined condition — Apache's `env=` only tests one variable, with
         no OR across several. A request matching two categories logs
         twice; harmless for a diagnostic log nobody is asked to dedupe. --}}
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
