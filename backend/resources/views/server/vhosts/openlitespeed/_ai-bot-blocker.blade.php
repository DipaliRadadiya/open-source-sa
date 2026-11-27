{{-- Managed by the panel. Per-site file for the AI bot blocker: conf/openlitespeed/rewrites/, which the vhost
     includes inside its `rewrite {}` block ahead of its own rules. 8g-firewall.conf sorts before
     ai-bot-blocker.conf, the order the vhost had them in. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the AI bot blocker.
  {{-- Checked first, ahead of HTTPS-force, redirects and the front controller: a blocked bot gets [F]
       (403) immediately. Apache mod_rewrite syntax, which OLS implements here, not nginx's. --}}
  RewriteCond %{HTTP_USER_AGENT} ({{ $botBlock }}) [NC]
  RewriteRule ^ - [F,L]
