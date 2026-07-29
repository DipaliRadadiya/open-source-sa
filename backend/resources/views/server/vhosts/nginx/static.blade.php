{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
server {
    listen 80;
    listen [::]:80;

    server_name {{ $domain }} www.{{ $domain }};
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
