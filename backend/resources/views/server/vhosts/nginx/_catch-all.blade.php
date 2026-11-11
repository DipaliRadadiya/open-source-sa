{{-- Managed by the panel. Rewritten by `sites:resync`; manual edits are overwritten.

     The answer for a name no site on this server claims (bug #55). Without it
     nginx hands an unknown name to the first server block it loaded, which
     was a customer's site: its pages, its certificate, under any domain
     somebody points here. `default_server`, so the load order does not matter.

     Rendered twice, under v7's names (2026-11-11): `main` → sites-available/default
     (port 80), `ssl` → sites-available/000-default-ssl.conf (443). The first
     line is the marker {@see \App\Services\Server\WebServers\SharedConfigFile}
     reads to know the file is the panel's. --}}
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
    listen 443 ssl default_server;
@if ($ipv6)
    listen [::]:443 ssl default_server;
@endif
    server_name _;

@if ($rejectHandshake)
    {{-- nginx 1.19.4+: refuse the TLS handshake, so no certificate — and so
         no hostname on this server — is ever shown for a name it does not
         serve. --}}
    ssl_reject_handshake on;
@else
    {{-- Older nginx (Ubuntu 22.04 ships 1.18) cannot refuse a handshake, so
         it presents the panel's reserved self-signed pair, which names
         nothing real, and closes the connection. --}}
    ssl_certificate     {{ $tlsFallback['certificate'] }};
    ssl_certificate_key {{ $tlsFallback['private_key'] }};

    return 444;
@endif
}
@endif
