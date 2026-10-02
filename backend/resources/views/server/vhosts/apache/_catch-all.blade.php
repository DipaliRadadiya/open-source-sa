{{-- Managed by the panel. Rewritten by `sites:resync`; manual edits are overwritten.

     The answer for a name no site on this server claims (bug #55). Apache
     gives an unknown name to the first virtual host it loaded for that port,
     which was a customer's site: its pages and its certificate, under any
     domain somebody points here. conf-enabled is read before sites-enabled,
     so this is always first. The ServerName is reserved (.invalid) and can
     never be a site's. --}}
<VirtualHost *:80>
    ServerName panel-default.invalid

    <Location />
        Require all denied
    </Location>
</VirtualHost>

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
