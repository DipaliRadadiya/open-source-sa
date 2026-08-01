{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
server {
    listen 80;
    listen [::]:80;

    server_name {{ implode(' ', $serverNames) }};
    root {{ $documentRoot }};

    index index.html;

    access_log /var/log/nginx/{{ $domain }}.access.log;
    error_log  /var/log/nginx/{{ $domain }}.error.log;

    location / {
        try_files $uri $uri/ =404;
    }

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
