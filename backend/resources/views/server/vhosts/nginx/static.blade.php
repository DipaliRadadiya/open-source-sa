{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Rendered twice: `main` → {name}.conf (port 80, and the no-certificate reject on 443), `ssl` →
     {name}-le-ssl.conf or {name}-ssl.conf (port 443). Null renders both. The firewall's log_format goes
     in whichever file nginx loads first: `-le-ssl` sorts before `.conf`. --}}
@if ($waf && ($section === null || $section === ($certificate ? 'ssl' : 'main')))
{{-- The firewall log's format, which nginx accepts only at http level: a site file is included there,
     so each site declares its own, under its own name. Combined, plus which rule matched and what was
     done. --}}
log_format {{ $waf['logFormat'] }} '$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent" waf=$waf_reason action={{ $waf['mode'] === 'enforce' ? 'blocked' : 'detected' }}';
@endif
@if ($forceHttps && $section !== 'ssl')
{{-- Plain HTTP exists only to send visitors to HTTPS, except the ACME challenge, which must stay
     reachable on port 80 or renewal stops and the redirect points at an expired certificate. --}}
server {
    listen 80;
@if ($ipv6)
    listen [::]:80;
@endif

    server_name {{ implode(' ', $serverNames) }};
    {{-- Addresses this site's fail2ban jail banned, for this site only, not at the firewall. A glob, so a
         missing file is not an error. --}}
    include {{ $siteRules }}/panel-fail2ban[.]conf;

    {{-- Served from one shared directory rather than the site's own document root (node and proxy
         sites serve nothing from disk), and `^~` so it beats the front-controller rewrite: Let's Encrypt
         reads the 404 a WordPress site would answer as unauthorized, which costs one of five attempts an
         hour. --}}
    location ^~ /.well-known/acme-challenge/ {
        root {{ $challengeRoot }};
        default_type "text/plain";
@if ($basicAuth)
        auth_basic off;
@endif
    }

    location / {
@foreach ($uncoveredNames as $name)
        {{-- Not on the certificate: https://<name> is a TLS error, so send it to the primary. Inside
             `location /`, never at server level, so the ACME location above still answers for this name and
             the certificate can be reissued to include it. --}}
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
    {{-- `http2 on;` where this nginx has it (1.25.1+), `listen ... http2` elsewhere, chosen by
         NginxDriver::supportsHttp2Directive(). The new form is a hard error before 1.25 and a failed config
         test takes every site on the box down; the old one only warns, once per site, on every `nginx -t`. --}}
@if ($http2On)
    listen 443 ssl{{ $http2On ? '' : ' http2' }};
@if ($ipv6)
    listen [::]:443 ssl{{ $http2On ? '' : ' http2' }};
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

    {{-- TLS 1.2 as the floor: 1.0 and 1.1 are deprecated and fail PCI checks, and allowing them only
         helps browsers that stopped getting security updates years ago. --}}
    ssl_protocols TLSv1.2 TLSv1.3;
    {{-- Off deliberately: with TLS 1.3 the client's preference is the better one, and forcing the
         server's order pins boxes to an older suite than both sides support. --}}
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    {{-- Tickets off for forward secrecy: a stolen ticket key decrypts every session it ever issued. --}}
    ssl_session_tickets off;
@elseif (! $certificate)
    {{-- Keep this hostname owned on 443 after SSL uninstall: without an exact SNI match nginx serves the
         first SSL vhost on the box, another customer's application. --}}
    listen 443 ssl{{ $http2On ? '' : ' http2' }};
@if ($ipv6)
    listen [::]:443 ssl{{ $http2On ? '' : ' http2' }};
@endif
@if ($rejectHandshake)
    {{-- nginx 1.19.4+ refuses the handshake before presenting a certificate. --}}
    ssl_reject_handshake on;
@else
    {{-- Older nginx (Ubuntu 22.04 ships 1.18) has no ssl_reject_handshake and fails the config test on
         it. It presents the panel's reserved pair, which names nothing real, as the catch-all does, and
         closes HTTPS for this name until it has a certificate. Port 80 is unaffected. --}}
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
    {{-- Addresses this site's fail2ban jail banned, for this site only, not at the firewall. A glob, so a
         missing file is not an error. --}}
    include {{ $siteRules }}/panel-fail2ban[.]conf;
@if ($basicAuth)
    auth_basic           "Restricted";
    auth_basic_user_file {{ $basicAuth['htpasswdPath'] }};
@endif

    {{-- Served from one shared directory rather than the site's own document root (node and proxy
         sites serve nothing from disk), and `^~` so it beats the front-controller rewrite: Let's Encrypt
         reads the 404 a WordPress site would answer as unauthorized, which costs one of five attempts an
         hour. --}}
    location ^~ /.well-known/acme-challenge/ {
        root {{ $challengeRoot }};
        default_type "text/plain";
@if ($basicAuth)
        auth_basic off;
@endif
    }

    root {{ $documentRoot }};

    index index.html;

    {{-- The HTTPS block logs to `-ssl` files; fail2ban and the Logs screen read both. --}}
    access_log {{ $logDir }}/access{{ $section === 'ssl' ? '-ssl' : '' }}.log;
    error_log  {{ $logDir }}/error{{ $section === 'ssl' ? '-ssl' : '' }}.log;

@if ($disabled)
    {{-- Disabled: every path answers 503 with the self-contained unavailable page, not a 200 that monitors
         and crawlers read as up. The ACME location above still answers, so renewal keeps working. --}}
    location / {
        return 503;
    }

    error_page 503 @unavailable;

    location @unavailable {
        rewrite ^ /index.html break;
        add_header Retry-After 3600 always;
    }
@else
    location / {
        try_files $uri $uri/ =404;
    }
@endif

    location ~ /\.(?!well-known) {
        deny all;
    }

    {{-- The per-site folder: every `*.conf` the user or an addon put there, loaded last in the server
         block ({@see \App\Services\Server\WebServers\SiteConfFolder}). --}}
    include {{ $siteConf }}/*.conf;
}
@endif

{{-- Redirects get their own server block: serving the same content under a second name splits its
     search ranking, a 301 keeps the authority on one. --}}
@foreach ($redirects as $redirect)
@php($redirectTls = $certificate && in_array($redirect->domain, $certificate->domains ?? [], true))
@if ($section !== 'ssl' || $redirectTls)
server {
@if ($redirectTls && $section !== 'main')
    {{-- A redirect needs its own HTTPS listener: a browser that has seen HSTS for `http://old` refuses the
         plaintext hop and never reaches the redirect. --}}
@if ($http2On)
    listen 443 ssl{{ $http2On ? '' : ' http2' }};
@if ($ipv6)
    listen [::]:443 ssl{{ $http2On ? '' : ' http2' }};
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

    {{-- Served from one shared directory rather than the site's own document root (node and proxy
         sites serve nothing from disk), and `^~` so it beats the front-controller rewrite: Let's Encrypt
         reads the 404 a WordPress site would answer as unauthorized, which costs one of five attempts an
         hour. --}}
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
