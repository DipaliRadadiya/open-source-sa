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
@if ($ipv6)
    listen [::]:80;
@endif

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
@if ($ipv6)
    listen [::]:443 ssl;
@endif
    http2 on;
@else
    listen 443 ssl http2;
@if ($ipv6)
    listen [::]:443 ssl http2;
@endif
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
    {{-- Keep this hostname owned on 443 after SSL uninstall. Without an exact
         SNI match nginx serves the first SSL vhost on the box — another
         customer's application. --}}
    listen 443 ssl;
@if ($ipv6)
    listen [::]:443 ssl;
@endif
@if ($rejectHandshake)
    {{-- nginx 1.19.4+ refuses the handshake before presenting a certificate. --}}
    ssl_reject_handshake on;
@else
    {{-- Older nginx (Ubuntu 22.04 ships 1.18) has no ssl_reject_handshake and
         fails the config test on it. It presents the panel's reserved pair,
         which names nothing real, as the catch-all does, and closes HTTPS for
         this name until it has a certificate. Port 80 is unaffected. --}}
    ssl_certificate     {{ $tlsFallback['certificate'] }};
    ssl_certificate_key {{ $tlsFallback['private_key'] }};
    if ($scheme = https) {
        return 444;
    }
@endif
@endif
@if (! $forceHttps && $section !== 'ssl')
    listen 80;
@if ($ipv6)
    listen [::]:80;
@endif
@endif

    server_name {{ implode(' ', $serverNames) }};
    {{-- Addresses this site's fail2ban jail banned — for this site only, not
         at the firewall (frontend QA FB-K). A glob, so a missing file is not
         an error. --}}
    include {{ $siteRules }}/panel-fail2ban[.]conf;
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


    {{-- The HTTPS block logs to `-ssl` files, as v7 does; fail2ban and the
         Logs screen read both. --}}
    access_log {{ $logDir }}/access{{ $section === 'ssl' ? '-ssl' : '' }}.log;
    error_log  {{ $logDir }}/error{{ $section === 'ssl' ? '-ssl' : '' }}.log;

    {{-- The site's own `post_max_size`, so the web server and PHP agree.
         nginx defaults this to 1 MB and nothing here used to set it, so a site
         whose pool said 512M still answered 413 at one megabyte — the request
         never reached PHP for its settings to matter. --}}
    client_max_body_size {{ $maxBodySize }};

@if (filled($application->nginx_location))
    {{-- The site's own block, as v7 kept it per Node app (`nginx_conf`) and
         its owner may have edited it. v7's `{basic_authentication}` slot is
         left empty: password protection is on the whole server block here. --}}
{!! str_replace('{basic_authentication}', '', $application->nginx_location) !!}
@else
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

        {{-- nginx's own "502 Bad Gateway" while the app is down — a restart,
             a crash, the seconds after a reboot before it listens — becomes
             a page that says so. Only nginx's own errors: with
             proxy_intercept_errors left off, a 502/503/504 the app itself
             sends is passed through untouched. Here rather than at server
             level, so nothing but the proxy is ever dressed as a restart.
             `=503` because without it nginx keeps the original code: live,
             the page went out as a 502, and Retry-After belongs to a 503. --}}
        error_page 502 503 504 =503 @restarting;
    }
@endif

    {{-- Inline rather than a file: nothing on disk to create, own or lose.
         The page holds no `'` or `$` (the string's quote, nginx's variables)
         and no `#`, which the panel's own vhost reader takes for a comment. --}}
    location @restarting {
        internal;
        default_type text/html;
        add_header Retry-After 10 always;
        add_header Cache-Control "no-store" always;
        return 503 '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>This site is restarting</title></head><body style="margin:0;font-family:system-ui,sans-serif;color:rgb(34,34,34);background:rgb(250,250,250)"><main style="max-width:32em;margin:15vh auto;padding:0 1.5em;line-height:1.5"><h1 style="font-size:1.5em;font-weight:600">This site is restarting</h1><p>This site is restarting. Please try again in a moment. If this keeps happening, the site owner should check the application&rsquo;s logs.</p></main></body></html>';
    }

    {{-- Version control and dotfiles must never be served. Ahead of the proxy
         so it applies even though the app, not nginx, owns the routing. --}}
    location ~ /\.(?!well-known) {
        deny all;
    }

    {{-- v7's per-site folder: every `*.conf` the user or v7 put there, loaded
         where v7 loaded it, last in the server block (vhosts the same as v7,
         2026-11-08; {@see \App\Services\Server\WebServers\SiteConfFolder}). --}}
    include {{ $siteConf }}/*.conf;
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
@if ($ipv6)
    listen [::]:443 ssl;
@endif
    http2 on;
@else
    listen 443 ssl http2;
@if ($ipv6)
    listen [::]:443 ssl http2;
@endif
@endif

    ssl_certificate     {{ $certificate->certificate_path }};
    ssl_certificate_key {{ $certificate->private_key_path }};
    ssl_protocols TLSv1.2 TLSv1.3;
@endif
@if ($section !== 'ssl')
    listen 80;
@if ($ipv6)
    listen [::]:80;
@endif
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
