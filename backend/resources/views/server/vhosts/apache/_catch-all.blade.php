{{-- Managed by the panel. Rewritten by `sites:resync`; manual edits are overwritten.

     The answer for a name no site on this server claims (bug #55). Apache
     gives an unknown name to the first virtual host it loaded for that port,
     which was a customer's site: its pages and its certificate, under any
     domain somebody points here. The ServerName is reserved (.invalid) and
     can never be a site's.

     Rendered twice, under v7's names (2026-11-11): `main` → 000-default.conf
     (port 80), `ssl` → 000-default-ssl.conf (443), in sites-enabled where
     `000-` sorts them first (SlugConflict refuses a site name that would sort
     before them). The first line is the panel's marker. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the answer for a domain no site on this server claims.
@if ($section === 'main')
<VirtualHost *:80>
    ServerName panel-default.invalid

    <Location />
        Require all denied
    </Location>
</VirtualHost>
@else
<IfModule ssl_module>
<VirtualHost *:443>
    ServerName panel-default.invalid

    SSLEngine on
    SSLCertificateFile    {{ $tlsFallback['certificate'] }}
    SSLCertificateKeyFile {{ $tlsFallback['private_key'] }}
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    <Location />
        Require all denied
    </Location>
</VirtualHost>
</IfModule>
@endif
