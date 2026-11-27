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
@if ($basicAuth)
@if ($basicAuth['serverOnly'])
    {{-- The application's own requests to itself (WordPress's wp-cron.php) skip the password, and only
         those: exact path AND a request from one of this server's own addresses. `auth_basic` treats a
         realm that evaluates to "off" as off, which allows a per-request decision. `if` here only sets
         variables, in server context, where it is safe. --}}
    set $sv_auth_realm "Restricted";
    set $sv_auth_open "";
    if ($uri ~ "^({{ collect($basicAuth['serverOnly']['paths'])->map(fn ($p) => preg_quote($p, '"'))->implode('|') }})$") {
        set $sv_auth_open "p";
    }
    if ($remote_addr ~ "^({{ collect($basicAuth['serverOnly']['addresses'])->map(fn ($a) => preg_quote($a, '"'))->implode('|') }})$") {
        set $sv_auth_open "${sv_auth_open}s";
    }
    if ($sv_auth_open = "ps") {
        set $sv_auth_realm off;
    }
    auth_basic           $sv_auth_realm;
@else
    auth_basic           "Restricted";
@endif
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

    index index.php index.html;

    {{-- The site's own `post_max_size`, so the web server and PHP agree. nginx defaults to 1 MB, so
         without this a site whose pool allows 512M still answers 413 at one megabyte. --}}
    client_max_body_size {{ $maxBodySize }};

    {{-- The HTTPS block logs to `-ssl` files; fail2ban and the Logs screen read both. --}}
    access_log {{ $logDir }}/access{{ $section === 'ssl' ? '-ssl' : '' }}.log;
    error_log  {{ $logDir }}/error{{ $section === 'ssl' ? '-ssl' : '' }}.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

@foreach ($wellKnown['redirects'] as $name => $target)
    {{-- Service discovery the application's own .htaccess does on Apache (Nextcloud CalDAV/CardDAV);
         without it calendar and contact clients cannot find the server. --}}
    location = /.well-known/{{ $name }} {
        return 301 {{ $target }};
    }
@endforeach
@if ($wellKnown['fallback'])
    {{-- Every other well-known URI to the application, as its .htaccess does. The ACME location above is
         a longer prefix and still wins; PKI validation files are served as they are. --}}
    location ^~ /.well-known/ {
        location ^~ /.well-known/pki-validation/ {
            try_files $uri =404;
        }

        return 301 {{ $wellKnown['fallback'] }}$request_uri;
    }
@endif
@foreach ($mimeTypes as $extension => $type)
    {{-- A type the application sets in its .htaccess: `.mjs` would go out as application/octet-stream,
         which browsers refuse to run as a module. --}}
    location ~* \.{{ $extension }}$ {
        types { }
        default_type {{ $type }};
        try_files $uri =404;
    }
@endforeach
@foreach ($deniedPaths as $pattern)
    {{-- The application ships this as an Apache `.htaccess` rule, which nginx never reads: without it
         logs, sessions and source under the web root are downloadable. Before the PHP location on purpose:
         regex locations are first-match, so after it a denied `.php` would still run. --}}
    location ~* {{ $pattern }} {
        deny all;
    }
@endforeach

    {{-- Extra rules an addon keeps for this site (the WordPress toolkit's xmlrpc and uploads-PHP blocks).
         Included, not rendered, so a domain, certificate or HTTPS change that rewrites this file leaves
         them in place. Before the PHP location on purpose: regex locations are first-match, so after it
         `/wp-content/uploads/x.php` would still run. A glob that matches nothing is not an error. --}}
    include {{ $siteRules }}/*.conf;

    {{-- `[^/]\.php(/|$)`, not `\.php$`: several applications address their scripts with a path appended
         (Moodle's slash arguments, `/theme/styles.php/boost/<rev>/all`). Anchored at the end, that URL
         falls through to `location /`, is handed to index.php, and the browser gets HTML where it asked
         for CSS.
         The leading `[^/]` keeps a bare directory ending in `.php` out; the snippet's own
         `try_files $fastcgi_script_name =404` still refuses to hand PHP-FPM a script that does not exist,
         which is what stops the wider pattern becoming an arbitrary-execution hole. --}}
    location ~ [^/]\.php(/|$) {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{{ $phpSocket }};
        {{-- 300 s, or longer when the site lets PHP run longer: nginx's own 60 s cuts long imports and saves
             off with a 504 while PHP is still working. --}}
        fastcgi_read_timeout {{ $fastcgiReadTimeout }};
    }

    {{-- Version control and dotfiles must never be served. A .git directory inside a web root is a full
         source disclosure. --}}
    location ~ /\.(?!well-known) {
        deny all;
    }
@if ($application->site_type !== 'nextcloud')

    {{-- Browser caching for static files. Not for Nextcloud, which sets its own headers. --}}
    location ~* \.(?:jpg|jpeg|gif|png|webp|avif|svg|ico|bmp|cur|tif|tiff|woff|woff2|ttf|otf|eot|mp3|aac|flac|wav|ogg|m4a|oga|webm|mp4|m4v|wasm|zip|pdf)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
        log_not_found off;
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~* \.(?:json|xml|txt|map)$ {
        expires 1h;
        add_header Cache-Control "public, must-revalidate";
        access_log off;
        log_not_found off;
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~* \.(?:css|js|mjs|jsx|ts|tsx)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
        access_log off;
        log_not_found off;
        try_files $uri $uri/ /index.php$is_args$args;
    }
@endif
@foreach ($subdirectoryFrontControllers as $i => $front)

    {{-- A directory that is an application of its own (PrestaShop's back office), which Apache routes with
         the `.htaccess` inside it. Last on purpose: regex locations are first-match, so the PHP, dotfile
         and denied-path rules above must see `.php`, `.htaccess` and logs first. --}}
    location ~ ^/(?<frontdir{{ $i }}>{{ $front['directory'] }})/ {
        try_files $uri $uri/ /$frontdir{{ $i }}/{{ $front['script'] }}$is_args$args;
    }
@endforeach

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
