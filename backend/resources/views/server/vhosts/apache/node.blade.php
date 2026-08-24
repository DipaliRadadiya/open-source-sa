{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Needs mod_proxy, mod_proxy_http and mod_proxy_wstunnel. The config test
     fails loudly if any is missing, which is the right outcome: a silently
     unproxied vhost would serve the site's source directory instead. --}}
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
         so the proxy cannot swallow it — otherwise the token is forwarded to
         the Node app, which answers 404, which Let's Encrypt reads as
         unauthorized and which costs one of five attempts an hour. --}}
    ProxyPass /.well-known/acme-challenge !
    Alias /.well-known/acme-challenge {{ $challengeRoot }}/.well-known/acme-challenge

    <Directory {{ $challengeRoot }}/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>

    RedirectMatch 301 ^/(?!\.well-known/acme-challenge/)(.*)$ https://{{ $serverNames[0] }}/$1
</VirtualHost>
@else
<VirtualHost *:80>
@include('server.vhosts.apache._node-body')
</VirtualHost>
@endif

@if ($certificate)
<VirtualHost *:443>
    SSLEngine on
    SSLCertificateFile    {{ $certificate->certificate_path }}
    SSLCertificateKeyFile {{ $certificate->private_key_path }}

    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off

    {{-- Without this the Node app sees `X-Forwarded-Proto: http` on a TLS
         request and builds every absolute URL with the wrong scheme — logins
         redirect to plaintext and cookies marked Secure are dropped. --}}
    RequestHeader set X-Forwarded-Proto "https"

@include('server.vhosts.apache._node-body')
</VirtualHost>
@endif

{{-- Redirects get their own VirtualHost. Serving the same content under a
     second name splits its search ranking between the two; a 301 keeps the
     authority on one. --}}
@foreach ($redirects as $redirect)
<VirtualHost *:80>
    ServerName {{ $redirect->domain }}
    Alias /.well-known/acme-challenge {{ $challengeRoot }}/.well-known/acme-challenge

    <Directory {{ $challengeRoot }}/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>

    RedirectMatch {{ $redirect->redirect_status }} ^/(?!\.well-known/acme-challenge/)(.*)$ {{ $redirect->redirect_to ?: $canonicalUrl }}/$1
</VirtualHost>
@if ($certificate && in_array($redirect->domain, $certificate->domains ?? [], true))
{{-- A redirect needs its own HTTPS listener. `http://old` → `https://new` looks
     like it needs no certificate of its own, but a browser that has ever seen
     HSTS for `old` refuses the plaintext hop and never reaches the redirect at
     all. --}}
<VirtualHost *:443>
    ServerName {{ $redirect->domain }}

    SSLEngine on
    SSLCertificateFile    {{ $certificate->certificate_path }}
    SSLCertificateKeyFile {{ $certificate->private_key_path }}
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    Redirect {{ $redirect->redirect_status }} / {{ $redirect->redirect_to ?: $canonicalUrl }}/
</VirtualHost>
@endif
@endforeach
