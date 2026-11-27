{{-- Managed by the panel. Per-site file for the AI bot blocker, loaded from the end of the site's server block
     by `include {site}/conf/nginx/*.conf`. Server-level rewrite directives run before any location is
     chosen and before auth_basic, wherever they stand in the block, so the order is kept. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the AI bot blocker.
    {{-- Blocked before auth_basic is evaluated, so a blocked bot gets a
         flat 403 and never sees the Basic Auth login prompt. --}}
    if ($http_user_agent ~* "({{ $botBlock }})") {
        return 403;
    }
