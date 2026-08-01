{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Needs mod_proxy, mod_proxy_http and mod_proxy_wstunnel. The config test
     fails loudly if any is missing, which is the right outcome: a silently
     unproxied vhost would serve the site's source directory instead. --}}
<VirtualHost *:80>
    ServerName {{ $serverNames[0] }}
@if (count($serverNames) > 1)
    ServerAlias {{ implode(' ', array_slice($serverNames, 1)) }}
@endif

    ErrorLog  ${APACHE_LOG_DIR}/{{ $domain }}.error.log
    CustomLog ${APACHE_LOG_DIR}/{{ $domain }}.access.log combined

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

    {{-- A .git directory inside a served tree is a full source disclosure. --}}
    <DirectoryMatch "/\.(?!well-known)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>

{{-- Redirects get their own VirtualHost. Serving the same content under a
     second name splits its search ranking between the two; a 301 keeps the
     authority on one. --}}
@foreach ($redirects as $redirect)
<VirtualHost *:80>
    ServerName {{ $redirect->domain }}
    Redirect {{ $redirect->redirect_status }} / {{ $redirect->redirect_to ?: 'https://'.$domain }}/
</VirtualHost>
@endforeach
