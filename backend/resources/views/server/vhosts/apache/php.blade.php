{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
<VirtualHost *:80>
    ServerName {{ $domain }}
    ServerAlias www.{{ $domain }}
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
