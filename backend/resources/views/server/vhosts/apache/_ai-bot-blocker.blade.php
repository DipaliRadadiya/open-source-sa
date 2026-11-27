{{-- Managed by the panel. Per-site file for the AI bot blocker, loaded by
     `IncludeOptional {site}/conf/apache/*` at the end of the vhost.
     A rewrite [F]: in a file of its own it cannot join the vhost's <Directory> Require (a second
     <Directory> for the same path would replace it). A vhost-level rewrite runs at translate-name,
     before Basic Auth, so a blocked bot gets a flat 403, never the login prompt. The ACME challenge is
     never blocked. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the AI bot blocker.
SetEnvIfNoCase User-Agent "({{ $botBlock }})" ai_bot_blocked
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteCond %{ENV:ai_bot_blocked} !^$
RewriteRule ^ - [F]
