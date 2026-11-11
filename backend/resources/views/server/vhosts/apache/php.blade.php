{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Rendered twice, as v7 lays a site out (step B1): `main` → {name}.conf (port
     80, and the no-certificate reject on 443), `ssl` → {name}-le-ssl.conf or
     {name}-ssl.conf (port 443). Null renders both. --}}
@if ($section !== 'ssl')
@if ($forceHttps)
{{-- Plain HTTP exists only to send visitors to HTTPS — with one exception,
     and it is not optional: the ACME challenge has to stay reachable on
     port 80 or renewal stops working, and the redirect goes on pointing
     confidently at a certificate that has expired. --}}
<VirtualHost *:80>
    ServerName {{ $serverNames[0] }}
@if (count($serverNames) > 1)
    ServerAlias {{ implode(' ', array_slice($serverNames, 1)) }}
@endif
    {{-- Served from one shared directory rather than the site's own document
         root: node and proxy sites serve nothing from disk, so there would be
         nowhere for certbot to drop the token. Aliased ahead of everything else
         so a front-controller rewrite cannot swallow it — a WordPress site
         would otherwise answer with its 404 page, which Let's Encrypt reads as
         unauthorized and which costs one of five attempts an hour. --}}
    Alias /.well-known/acme-challenge {{ $challengeRoot }}/.well-known/acme-challenge

    <Directory {{ $challengeRoot }}/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>

    {{-- To the name the visitor asked for, as v7 and the other two web
         servers do: www stays www. Only this site's own names reach this
         vhost (an unknown name goes to the catch-all), so the Host header
         cannot send anyone elsewhere. --}}
    RewriteEngine On
@foreach ($uncoveredNames as $name)
    {{-- Not on the certificate: https://{{ $name }} is a TLS error, so send
         it to the primary. ACME stays excluded so the certificate can still
         be reissued to include this name. --}}
    RewriteCond %{HTTP_HOST} ^{{ preg_quote($name, '/') }}(:80)?$ [NC]
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://{{ $serverNames[0] }}%{REQUEST_URI} [R=301,L]
@endforeach
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [R=301,L]
</VirtualHost>
@else
<VirtualHost *:80>
@include('server.vhosts.apache._php-body')
</VirtualHost>
@endif
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

    {{-- TLS 1.2 as the floor. 1.0 and 1.1 are deprecated and fail PCI checks;
         allowing them buys compatibility only with browsers that stopped
         receiving security updates years ago. --}}
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    {{-- Off deliberately: with TLS 1.3 the client's preference is the better
         one, and forcing the server's order is how boxes end up pinned to an
         older suite than both sides support. --}}
    SSLHonorCipherOrder off

    {{-- HTTP/2 on HTTPS, as v7 had it. Only when the module is loaded:
         `Protocols h2` without it would be a no-op at best. --}}
    <IfModule http2_module>
        Protocols h2 http/1.1
    </IfModule>

@include('server.vhosts.apache._php-body', ['tlsBlock' => true])
</VirtualHost>
@endif
@elseif ($section !== 'ssl')
@include('server.vhosts.apache._tls-reject')
@endif

{{-- Redirects get their own VirtualHost. Serving the same content under a
     second name splits its search ranking between the two; a 301 keeps the
     authority on one. --}}
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
{{-- A redirect needs its own HTTPS listener. `http://old` → `https://new` looks
     like it needs no certificate of its own, but a browser that has ever seen
     HSTS for `old` refuses the plaintext hop and never reaches the redirect at
     all. --}}
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
