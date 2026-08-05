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
@endif
@if (! $forceHttps)
    listen 80;
    listen [::]:80;
@endif

    server_name {{ implode(' ', $serverNames) }};
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

    root {{ $documentRoot }};

    index index.php index.html;

    access_log /var/log/nginx/{{ $domain }}.access.log;
    error_log  /var/log/nginx/{{ $domain }}.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{{ $phpSocket }};
    }

    {{-- Version control and dotfiles must never be served. A .git directory
         inside a web root is a full source disclosure. --}}
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
        return {{ $redirect->redirect_status }} {{ $redirect->redirect_to ?: 'https://'.$domain }}$request_uri;
    }
}
@endforeach
