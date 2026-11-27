{{-- Managed by the panel. Rewritten by `sites:resync`; manual edits are overwritten.
     The answer for a name no site on this server claims: without it nginx hands an unknown name to the
     first server block it loaded, which would be a customer's site (its pages and certificate under any
     domain pointed here). `default_server`, so load order does not matter.
     Rendered twice: `main` → sites-available/default (port 80), `ssl` →
     sites-available/000-default-ssl.conf (443). The first line is the marker
     {@see \App\Services\Server\WebServers\SharedConfigFile} reads to know the file is the panel's. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the answer for a domain no site on this server claims.
@if ($section === 'main')
server {
    listen 80 default_server;
@if ($ipv6)
    listen [::]:80 default_server;
@endif
    server_name _;

    return 404;
}
@else
server {
    {{-- Every server on port 443 must ask for the same protocol options: before nginx 1.25.1 the sites need
         `listen ... http2`, and a server that differs makes `nginx -t` print "protocol options redefined"
         once per site. --}}
    listen 443 ssl{{ $http2On ? '' : ' http2' }} default_server;
@if ($ipv6)
    listen [::]:443 ssl{{ $http2On ? '' : ' http2' }} default_server;
@endif
    server_name _;

@if ($rejectHandshake)
    {{-- nginx 1.19.4+: refuse the TLS handshake, so no certificate (and no hostname on this server) is
         shown for a name it does not serve. --}}
    ssl_reject_handshake on;
@else
    {{-- Older nginx (Ubuntu 22.04 ships 1.18) cannot refuse a handshake, so it presents the panel's
         reserved self-signed pair, which names nothing real, and closes the connection. --}}
    ssl_certificate     {{ $tlsFallback['certificate'] }};
    ssl_certificate_key {{ $tlsFallback['private_key'] }};

    return 444;
@endif
}
@endif
