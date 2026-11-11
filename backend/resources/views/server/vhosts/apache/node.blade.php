{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Needs mod_proxy, mod_proxy_http and mod_proxy_wstunnel. The config test
     fails loudly if any is missing, which is the right outcome: a silently
     unproxied vhost would serve the site's source directory instead. --}}
@if ($section !== 'ssl')
{{-- Force HTTPS lives in the site's conf/apache/forceHttps.conf, as v7
     has it, which the body loads with the rest of that folder. --}}
<VirtualHost *:80>
@include('server.vhosts.apache._node-body')
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

    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off

    {{-- Without this the Node app sees `X-Forwarded-Proto: http` on a TLS
         request and builds every absolute URL with the wrong scheme — logins
         redirect to plaintext and cookies marked Secure are dropped. --}}
    RequestHeader set X-Forwarded-Proto "https"

    {{-- HTTP/2 on HTTPS, as v7 had it. Only when the module is loaded:
         `Protocols h2` without it would be a no-op at best. --}}
    <IfModule http2_module>
        Protocols h2 http/1.1
    </IfModule>

@include('server.vhosts.apache._node-body', ['tlsBlock' => true])
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
