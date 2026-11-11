{{-- Managed by the panel. v7's per-site file for the AI bot blocker, in v7's
     place (Dipali, 2026-11-11), loaded by `IncludeOptional {site}/conf/apache/*`
     at the end of the vhost.

     A rewrite [F], as v7's own file does it: in a file of its own it cannot
     reach into the vhost's <Directory> Require (a second <Directory> for the
     same path would replace it, not join it). Vhost-level rewrite runs at
     translate-name, before Basic Auth, so a blocked bot still gets a flat 403
     and never the login prompt. The ACME challenge is never blocked, as it was
     never inside the document root's Require. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the AI bot blocker.
SetEnvIfNoCase User-Agent "({{ $botBlock }})" ai_bot_blocked
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteCond %{ENV:ai_bot_blocked} !^$
RewriteRule ^ - [F]
