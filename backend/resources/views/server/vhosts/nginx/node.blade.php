{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
server {
    listen 80;
    listen [::]:80;

    server_name {{ implode(' ', $serverNames) }};

    access_log /var/log/nginx/{{ $domain }}.access.log;
    error_log  /var/log/nginx/{{ $domain }}.error.log;

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
    listen 80;
    listen [::]:80;

    server_name {{ $redirect->domain }};

    return {{ $redirect->redirect_status }} {{ $redirect->redirect_to ?: 'https://'.$domain }}$request_uri;
}
@endforeach
