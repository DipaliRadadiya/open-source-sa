{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
@if ($forceHttps)
{{-- Plain HTTP exists only to send visitors to HTTPS — with one exception, and
     it is not optional: the ACME challenge has to stay reachable on port 80 or
     renewal stops working, and the redirect goes on pointing confidently at a
     certificate that has expired. --}}
server {
    listen 80;
    listen [::]:80;

    server_name {{ implode(' ', $serverNames) }};

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
        return 301 https://$host$request_uri;
    }
}
@endif

server {
@if ($certificate)
    {{-- `listen ... http2` rather than the newer `http2 on;`. The new form is
         a hard error on nginx before 1.25, which is what Ubuntu 24.04 ships;
         this form is merely deprecated on newer builds. A deprecation warning
         is survivable, a config test that fails takes every site on the box
         down with it. --}}
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

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
@else
    {{-- Own this SNI name even without a certificate, so nginx rejects the
         handshake instead of falling through to another application's first
         SSL vhost. --}}
    listen 443 ssl;
    listen [::]:443 ssl;
    ssl_reject_handshake on;
@endif
@if (! $forceHttps)
    listen 80;
    listen [::]:80;
@endif

    server_name {{ implode(' ', $serverNames) }};
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
@foreach ($waf['exceptions'] as $exception)
    if ($request_uri ~* "{{ preg_quote($exception, '/') }}") { set $waf_exception "1"; }
    if ($args ~* "{{ preg_quote($exception, '/') }}") { set $waf_exception "1"; }
    if ($http_user_agent ~* "{{ preg_quote($exception, '/') }}") { set $waf_exception "1"; }
@endforeach
@if (in_array('query_string', $waf['categories'], true))
    if ($bad_querystring_ng) { set $waf_block "1"; }
@endif
@if (in_array('request_uri', $waf['categories'], true))
    if ($bad_request_ng) { set $waf_block "1"; }
@endif
@if (in_array('user_agent', $waf['categories'], true))
    if ($bad_bot_ng) { set $waf_block "1"; }
@endif
@if (in_array('referrer', $waf['categories'], true))
    if ($bad_referer_ng) { set $waf_block "1"; }
@endif
@if (in_array('cookie', $waf['categories'], true))
    if ($bad_cookie_ng) { set $waf_block "1"; }
@endif
@if (in_array('method', $waf['categories'], true))
    if ($not_allowed_method_ng) { set $waf_block "1"; }
@endif
@foreach ($waf['customRules'] as $rule)
    if ($request_uri ~* "{{ preg_quote($rule, '/') }}") { set $waf_block "1"; }
    if ($args ~* "{{ preg_quote($rule, '/') }}") { set $waf_block "1"; }
@endforeach
    set $waf_decision "${waf_block}${waf_exception}";
@if ($waf['mode'] === 'enforce')
    if ($waf_decision = "10") {
        return 403;
    }
@else
    set $waf_would_block "0";
    if ($waf_decision = "10") { set $waf_would_block "1"; }
    access_log {{ $waf['detectLogPath'] }} combined if=$waf_would_block;
@endif
@endif
@if ($botBlock)
    {{-- Blocked before auth_basic is evaluated, so a blocked bot gets a
         flat 403 and never sees the Basic Auth login prompt. --}}
    if ($http_user_agent ~* "^({{ $botBlock }})") {
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


    access_log /var/log/nginx/{{ $logName }}.access.log;
    error_log  /var/log/nginx/{{ $logName }}.error.log;

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

{{-- Redirects get their own server block. Serving the same content under a
     second name splits its search ranking between the two; a 301 keeps the
     authority on one. --}}
@foreach ($redirects as $redirect)
server {
@if ($certificate && in_array($redirect->domain, $certificate->domains ?? [], true))
    {{-- A redirect needs its own HTTPS listener. `http://old` → `https://new`
         looks like it needs no certificate, but a browser that has seen HSTS
         for `old` refuses the plaintext hop and never reaches the redirect. --}}
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    ssl_certificate     {{ $certificate->certificate_path }};
    ssl_certificate_key {{ $certificate->private_key_path }};
    ssl_protocols TLSv1.2 TLSv1.3;
@endif
    listen 80;
    listen [::]:80;

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
        return {{ $redirect->redirect_status }} {{ $redirect->redirect_to ?: $canonicalUrl }}$request_uri;
    }
}
@endforeach
