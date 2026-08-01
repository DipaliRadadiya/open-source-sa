{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
server {
    listen 80;
    listen [::]:80;

    server_name {{ implode(' ', $serverNames) }};
    root {{ $documentRoot }};

    index index.php index.html;

    access_log /var/log/nginx/{{ $domain }}.access.log;
    error_log  /var/log/nginx/{{ $domain }}.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php{{ $phpVersion }}-fpm.sock;
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
    listen 80;
    listen [::]:80;

    server_name {{ $redirect->domain }};

    return {{ $redirect->redirect_status }} {{ $redirect->redirect_to ?: 'https://'.$domain }}$request_uri;
}
@endforeach
