{{-- Managed by the panel. v7's per-site file for the AI bot blocker (Dipali, 2026-11-11:
     v7's places), loaded from the end of the site's server block by
     `include {site}/conf/nginx/*.conf`. Server-level rewrite directives run
     before any location is chosen and before auth_basic, wherever they stand
     in the block, so the order the vhost had them in is kept. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the AI bot blocker.
    {{-- Blocked before auth_basic is evaluated, so a blocked bot gets a
         flat 403 and never sees the Basic Auth login prompt. --}}
    if ($http_user_agent ~* "({{ $botBlock }})") {
        return 403;
    }
