{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Rendered twice: `main` → {name}.conf (port 80, and the no-certificate reject on 443), `ssl` →
     {name}-le-ssl.conf or {name}-ssl.conf (port 443). Null renders both. --}}
@if ($section !== 'ssl')
{{-- Force HTTPS lives in the site's conf/apache/forceHttps.conf, which the body loads with the rest
     of that folder. --}}
<VirtualHost *:80>
@include('server.vhosts.apache._static-body')
</VirtualHost>
@endif

@if ($certificate)
@if ($section !== 'main')
<VirtualHost *:443>
    SSLEngine on
    SSLCertificateFile    {{ $certificate->certificate_path }}
    SSLCertificateKeyFile {{ $certificate->private_key_path }}
@if ($certificate->servesSeparateChain())
    SSLCertificateChainFile {{ $certificate->chain_path }}
@endif

    {{-- TLS 1.2 as the floor: 1.0 and 1.1 are deprecated and fail PCI checks, and allowing them only
         helps browsers that stopped getting security updates years ago. --}}
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    {{-- Off deliberately: with TLS 1.3 the client's preference is the better one, and forcing the
         server's order pins boxes to an older suite than both sides support. --}}
    SSLHonorCipherOrder off

    {{-- HTTP/2 on HTTPS, only when the module is loaded: `Protocols h2` without it would be a no-op. --}}
    <IfModule http2_module>
        Protocols h2 http/1.1
    </IfModule>

@include('server.vhosts.apache._static-body', ['tlsBlock' => true])
</VirtualHost>
@endif
@elseif ($section !== 'ssl')
@include('server.vhosts.apache._tls-reject')
@endif

{{-- Redirects get their own VirtualHost: serving the same content under a second name splits
     its search ranking, a 301 keeps the authority on one. --}}
@foreach ($redirects as $redirect)
@if ($section !== 'ssl')
<VirtualHost *:80>
    ServerName {{ $redirect->domain }}
    Alias /.well-known/acme-challenge {{ $challengeRoot }}/.well-known/acme-challenge

    <Directory {{ $challengeRoot }}/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>

    RedirectMatch {{ $redirect->redirect_status }} ^/(?!\.well-known/acme-challenge/)(.*)$ {{ $redirect->redirectTarget() ?: $canonicalUrl }}/$1
</VirtualHost>
@endif
@if ($section !== 'main' && $certificate && in_array($redirect->domain, $certificate->domains ?? [], true))
{{-- A redirect needs its own HTTPS listener: a browser that has seen HSTS for `http://old` refuses
     the plaintext hop and never reaches the redirect. --}}
<VirtualHost *:443>
    ServerName {{ $redirect->domain }}

    SSLEngine on
    SSLCertificateFile    {{ $certificate->certificate_path }}
    SSLCertificateKeyFile {{ $certificate->private_key_path }}
@if ($certificate->servesSeparateChain())
    SSLCertificateChainFile {{ $certificate->chain_path }}
@endif
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    Redirect {{ $redirect->redirect_status }} / {{ $redirect->redirectTarget() ?: $canonicalUrl }}/
</VirtualHost>
@endif
@endforeach
