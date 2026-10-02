{{-- Managed by the panel. Rewritten by `sites:resync`; manual edits are overwritten.

     The answer for a name no site on this server claims (bug #55). Without it
     nginx hands an unknown name to the first server block it loaded, which
     was a customer's site: its pages, its certificate, under any domain
     somebody points here. In conf.d so it loads before sites-enabled, and
     `default_server` so the order does not matter anyway. --}}
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    return 404;
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
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
