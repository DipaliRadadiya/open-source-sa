{{-- Apache cannot reject an SNI hostname before selecting a certificate. A
     reserved-name self-signed pair lets this exact hostname terminate into a
     denied vhost rather than Apache's first real TLS application. Browsers see
     a certificate error, which is honest after SSL uninstall; they never see
     another customer's site. --}}
<VirtualHost *:443>
    ServerName {{ $serverNames[0] }}
@if (count($serverNames) > 1)
    ServerAlias {{ implode(' ', array_slice($serverNames, 1)) }}
@endif

    SSLEngine on
    SSLCertificateFile    {{ $tlsFallback['certificate'] }}
    SSLCertificateKeyFile {{ $tlsFallback['private_key'] }}
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    <Location />
        Require all denied
    </Location>
</VirtualHost>
