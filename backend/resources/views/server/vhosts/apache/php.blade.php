{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
<VirtualHost *:80>
    ServerName {{ $serverNames[0] }}
@if (count($serverNames) > 1)
    ServerAlias {{ implode(' ', array_slice($serverNames, 1)) }}
@endif
    DocumentRoot {{ $documentRoot }}

    <Directory {{ $documentRoot }}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php{{ $phpVersion }}-fpm.sock|fcgi://localhost"
    </FilesMatch>

    {{-- A .git directory inside a web root is a full source disclosure. --}}
    <DirectoryMatch "/\.(?!well-known)">
        Require all denied
    </DirectoryMatch>

    ErrorLog  ${APACHE_LOG_DIR}/{{ $domain }}.error.log
    CustomLog ${APACHE_LOG_DIR}/{{ $domain }}.access.log combined
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
