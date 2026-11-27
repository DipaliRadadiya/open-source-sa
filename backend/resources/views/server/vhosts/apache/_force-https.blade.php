{{-- Managed by the panel. Per-site forceHttps.conf, loaded by both of the site's vhosts; on 443
     `%{HTTPS}` is on and nothing here applies. Redirects to the name the visitor asked for (www stays
     www); a name the certificate does not cover goes to the primary, since https://<that name> would
     be a TLS error. Never the ACME challenge, or renewal stops working. Rewrite at translate-name,
     before Basic Auth: http is sent to https before any password is asked. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: force HTTPS.
RewriteEngine On
@foreach ($uncoveredNames as $name)
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP_HOST} ^{{ preg_quote($name, '/') }}(:80)?$ [NC]
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteRule ^ https://{{ $serverNames[0] }}%{REQUEST_URI} [R=301,L]
@endforeach
RewriteCond %{HTTPS} !=on
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [R=301,L]
