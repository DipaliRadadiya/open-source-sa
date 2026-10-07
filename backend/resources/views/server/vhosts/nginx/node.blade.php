{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Rendered twice, as v7 lays a site out (step B1): `main` → {name}.conf (port
     80, and the no-certificate reject on 443), `ssl` → {name}-le-ssl.conf or
     {name}-ssl.conf (port 443). Null renders both. The firewall's log_format
     goes in whichever file nginx loads first: `-le-ssl` sorts before `.conf`. --}}
@if ($waf && ($section === null || $section === ($certificate ? 'ssl' : 'main')))
{{-- The firewall log's format, which nginx only accepts at http level: a site
     file is included there, so each site declares its own, under its own name.
     Combined, plus which rule matched and what was done (bug #84). --}}
log_format {{ $waf['logFormat'] }} '$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent" waf=$waf_reason action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}';
@endif
@if ($forceHttps && $section !== 'ssl')
{{-- Plain HTTP exists only to send visitors to HTTPS — with one exception, and
     it is not optional: the ACME challenge has to stay reachable on port 80 or
     renewal stops working, and the redirect goes on pointing confidently at a
     certificate that has expired. --}}
server {
    listen 80;
    listen [::]:80;

    server_name {{ implode(' ', $serverNames) }};
    {{-- Addresses this site's fail2ban jail banned — for this site only, not
         at the firewall (frontend QA FB-K). A glob, so a missing file is not
         an error. --}}
    include {{ $siteRules }}/panel-fail2ban[.]conf;

    {{-- Served from one shared directory rather than the site's own document
         root: node and proxy sites serve nothing from disk, so there would be
         nowhere for certbot to drop the token. `^~` so it beats the
         front-controller rewrite — a WordPress site would otherwise hand the
         token to index.php and answer with its 404 page, which Let's Encrypt
         reads as unauthorized and which costs one of five attempts an hour. --}}
    location ^~ /.well-known/acme-challenge/ {
        root {{ $challengeRoot }};
        default_type "text/plain";
@if ($basicAuth)
        auth_basic off;
@endif
    }

    location / {
@foreach ($uncoveredNames as $name)
        {{-- Not on the certificate: https://{{ $name }} is a TLS error, so
             send it to the primary. Inside `location /`, never at server
             level, so the ACME location above still answers for this name
             and the certificate can be reissued to include it. --}}
        if ($host = {{ $name }}) {
            return 301 https://{{ $serverNames[0] }}$request_uri;
        }
@endforeach
        return 301 https://$host$request_uri;
    }
}
@endif

@if ($section === null || ($section === 'ssl' ? $certificate : (! $certificate || ! $forceHttps)))
server {
@if ($certificate && $section !== 'main')
    {{-- `http2 on;` where this nginx has it (1.25.1+), `listen ... http2`
         elsewhere — chosen by NginxDriver::supportsHttp2Directive(). The new
         form is a hard error before 1.25 (Ubuntu 24.04 ships 1.24), and a
         failed config test takes every site on the box down; the old one is
         only deprecated after it, but printed a warning per site on every
         `nginx -t` (36 on an 18-site Ubuntu 26.04 box). --}}
@if ($http2On)
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
@else
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
@endif

    ssl_certificate     {{ $certificate->certificate_path }};
    ssl_certificate_key {{ $certificate->private_key_path }};

    {{-- TLS 1.2 as the floor. 1.0 and 1.1 are deprecated and fail PCI checks;
         allowing them buys compatibility only with browsers that stopped
         receiving security updates years ago. --}}
    ssl_protocols TLSv1.2 TLSv1.3;
    {{-- Off deliberately: with TLS 1.3 the client's preference is the better
         one, and forcing the server's order is how boxes end up pinned to an
         older suite than both sides support. --}}
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    {{-- Tickets off for forward secrecy: a stolen ticket key decrypts every
         session it ever issued, which is the property TLS 1.3 exists to
         remove. --}}
    ssl_session_tickets off;
@elseif (! $certificate)
    {{-- Own this SNI name even without a certificate, so nginx rejects the
         handshake instead of falling through to another application's first
         SSL vhost. --}}
    listen 443 ssl;
    listen [::]:443 ssl;
    ssl_reject_handshake on;
@endif
@if (! $forceHttps && $section !== 'ssl')
    listen 80;
    listen [::]:80;
@endif

    server_name {{ implode(' ', $serverNames) }};
    {{-- Addresses this site's fail2ban jail banned — for this site only, not
         at the firewall (frontend QA FB-K). A glob, so a missing file is not
         an error. --}}
    include {{ $siteRules }}/panel-fail2ban[.]conf;
@if ($waf)
    {{-- 8G Firewall: checked before the AI bot block and Basic Auth — a
         request that looks like an exploit attempt should get a flat 403
         without ever being evaluated as a bot or being offered a login
         prompt. `$waf_block`/`$waf_exception` use the concatenation trick
         (`$waf_decision = "10"` means blocked-and-not-excepted) because
         nginx's `if` has no boolean AND — the same idiom used to restrict
         a staging site to one IP in every worked nginx example of it. --}}
    set $waf_block "0";
    set $waf_exception "0";
    set $waf_reason "-";
@foreach ($waf['exceptions'] as $exception)
    {{-- The path the visitor asked for — never the query (bug #82: matched
         against the query string or the user agent, an exception was a
         password anyone could type). `$request_uri`, not `$uri`: on a front
         controller `try_files` redirects internally to /index.php, this
         block runs again with `$uri` = /index.php, the exception no longer
         matched and the request was blocked anyway — so an exception never
         helped WordPress /wp-json/ or a Laravel route, the cases it exists
         for (frontend QA FS-C43). `^[^?]*` keeps the match in the path. --}}
    if ($request_uri ~* "^[^?]*{!! $exception !!}") { set $waf_exception "1"; }
@endforeach
@if ($waf['exceptions'] !== [])
    {{-- `$request_uri` is the path as sent, before nginx resolves `..`:
         `/allowed/../wp-login.php` would carry the exception to a page it
         was never meant for. No exception for a path with dot segments or
         doubled slashes, encoded or not. --}}
    if ($request_uri ~* "^[^?]*(/\.\.|%2e%2e|\.%2e|%2e\.|//|%2f)") { set $waf_exception "0"; }
@endif
@if (in_array('query_string', $waf['categories'], true))
    if ($bad_querystring_ng) { set $waf_block "1"; set $waf_reason "query_string"; }
@endif
@if (in_array('request_uri', $waf['categories'], true))
    if ($bad_request_ng) { set $waf_block "1"; set $waf_reason "request_uri"; }
@endif
@if (in_array('user_agent', $waf['categories'], true))
    if ($bad_bot_ng) { set $waf_block "1"; set $waf_reason "user_agent"; }
@endif
@if (in_array('referrer', $waf['categories'], true))
    if ($bad_referer_ng) { set $waf_block "1"; set $waf_reason "referrer"; }
@endif
@if (in_array('cookie', $waf['categories'], true))
    if ($bad_cookie_ng) { set $waf_block "1"; set $waf_reason "cookie"; }
@endif
@if (in_array('method', $waf['categories'], true))
    if ($not_allowed_method_ng) { set $waf_block "1"; set $waf_reason "method"; }
@endif
@foreach ($waf['customRules'] as $rule)
    if ($request_uri ~* "{!! $rule !!}") { set $waf_block "1"; set $waf_reason "custom_rule"; }
    if ($args ~* "{!! $rule !!}") { set $waf_block "1"; set $waf_reason "custom_rule"; }
@endforeach
    set $waf_decision "${waf_block}${waf_exception}";
    {{-- Logged in both modes (bug #84): blocking used to leave no record of
         what it blocked. The format is the site's own, declared above. --}}
    set $waf_matched "0";
    if ($waf_decision = "10") { set $waf_matched "1"; }
    access_log {{ $waf['detectLogPath'] }} {{ $waf['logFormat'] }} if=$waf_matched;
@if ($waf['mode'] === 'enforce')
    if ($waf_decision = "10") {
        return 403;
    }
@endif
@endif
@if ($botBlock)
    {{-- Blocked before auth_basic is evaluated, so a blocked bot gets a
         flat 403 and never sees the Basic Auth login prompt. --}}
    if ($http_user_agent ~* "({{ $botBlock }})") {
        return 403;
    }
@endif
@if ($basicAuth)
    auth_basic           "Restricted";
    auth_basic_user_file {{ $basicAuth['htpasswdPath'] }};
@endif

    {{-- Served from one shared directory rather than the site's own document
         root: node and proxy sites serve nothing from disk, so there would be
         nowhere for certbot to drop the token. `^~` so it beats the
         front-controller rewrite — a WordPress site would otherwise hand the
         token to index.php and answer with its 404 page, which Let's Encrypt
         reads as unauthorized and which costs one of five attempts an hour. --}}
    location ^~ /.well-known/acme-challenge/ {
        root {{ $challengeRoot }};
        default_type "text/plain";
@if ($basicAuth)
        auth_basic off;
@endif
    }


    access_log {{ $logDir }}/access.log;
    error_log  {{ $logDir }}/error.log;

    {{-- The site's own `post_max_size`, so the web server and PHP agree.
         nginx defaults this to 1 MB and nothing here used to set it, so a site
         whose pool said 512M still answered 413 at one megabyte — the request
         never reached PHP for its settings to matter. --}}
    client_max_body_size {{ $maxBodySize }};

    location / {
        proxy_pass http://127.0.0.1:{{ $appPort }};

        {{-- 1.1 and the Upgrade pair are what make WebSockets work. Node apps
             reach for them constantly — live dashboards, chat, hot reload —
             and without these three lines the connection is answered with a
             plain 200 and the client hangs waiting for a handshake that never
             comes. Cheap to include, mystifying to debug when absent. --}}
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        {{-- The usual recipe uses a `$connection_upgrade` map, which has to
             live in the `http` block — we only own a server block here, so
             referencing it would fail the config test with "unknown variable".
             Passing the client's own Connection header through is correct for
             both cases and needs nothing declared elsewhere: an upgrade
             request already carries `Connection: Upgrade`, and an ordinary one
             carries keep-alive. Hardcoding "upgrade" would announce an upgrade
             on every request that never asked for one. --}}
        proxy_set_header Connection $http_connection;

        {{-- Without these the app sees every request as coming from 127.0.0.1
             over http, so redirects point at the wrong scheme, rate limiting
             sees one client, and logs record the proxy rather than the
             visitor. --}}
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;

        {{-- A slow first response after a restart should wait, not 502. --}}
        proxy_connect_timeout 10s;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;

        {{-- Streamed responses and server-sent events must not sit in a buffer
             until they are complete — that is the whole point of them. --}}
        proxy_buffering off;
        proxy_cache_bypass $http_upgrade;
    }

    {{-- Version control and dotfiles must never be served. Ahead of the proxy
         so it applies even though the app, not nginx, owns the routing. --}}
    location ~ /\.(?!well-known) {
        deny all;
    }
}
@endif

{{-- Redirects get their own server block. Serving the same content under a
     second name splits its search ranking between the two; a 301 keeps the
     authority on one. --}}
@foreach ($redirects as $redirect)
@php($redirectTls = $certificate && in_array($redirect->domain, $certificate->domains ?? [], true))
@if ($section !== 'ssl' || $redirectTls)
server {
@if ($redirectTls && $section !== 'main')
    {{-- A redirect needs its own HTTPS listener. `http://old` → `https://new`
         looks like it needs no certificate, but a browser that has seen HSTS
         for `old` refuses the plaintext hop and never reaches the redirect. --}}
@if ($http2On)
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
@else
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
@endif

    ssl_certificate     {{ $certificate->certificate_path }};
    ssl_certificate_key {{ $certificate->private_key_path }};
    ssl_protocols TLSv1.2 TLSv1.3;
@endif
@if ($section !== 'ssl')
    listen 80;
    listen [::]:80;
@endif

    server_name {{ $redirect->domain }};

    {{-- Served from one shared directory rather than the site's own document
         root: node and proxy sites serve nothing from disk, so there would be
         nowhere for certbot to drop the token. `^~` so it beats the
         front-controller rewrite — a WordPress site would otherwise hand the
         token to index.php and answer with its 404 page, which Let's Encrypt
         reads as unauthorized and which costs one of five attempts an hour. --}}
    location ^~ /.well-known/acme-challenge/ {
        root {{ $challengeRoot }};
        default_type "text/plain";
@if ($basicAuth)
        auth_basic off;
@endif
    }

    location / {
        return {{ $redirect->redirect_status }} {{ $redirect->redirectTarget() ?: $canonicalUrl }}$request_uri;
    }
}
@endif
@endforeach
