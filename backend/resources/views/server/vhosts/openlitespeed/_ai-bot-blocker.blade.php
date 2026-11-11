{{-- Managed by the panel. v7's per-site file for the AI bot blocker, in v7's place
     (Dipali, 2026-11-11): conf/openlitespeed/rewrites/, which the vhost
     includes inside its `rewrite {}` block ahead of its own rules, as v7's does.
     8g-firewall.conf sorts before ai-bot-blocker.conf, the order the vhost had
     them in. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the AI bot blocker.
  {{-- Checked first, ahead of HTTPS-force/redirects/the front controller —
       a blocked bot gets [F] (403) immediately. Apache mod_rewrite syntax,
       which OLS implements here, not nginx's. --}}
  RewriteCond %{HTTP_USER_AGENT} ({{ $botBlock }}) [NC]
  RewriteRule ^ - [F,L]
