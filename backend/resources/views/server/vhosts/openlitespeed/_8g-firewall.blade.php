{{-- Managed by the panel. v7's per-site file for the 8G firewall, in v7's place
     (Dipali, 2026-11-11): conf/openlitespeed/rewrites/, which the vhost
     includes inside its `rewrite {}` block ahead of its own rules, as v7's does.
     8g-firewall.conf sorts before ai-bot-blocker.conf, the order the vhost had
     them in. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the 8G firewall.
  {{-- 8G Firewall, before the bot block and everything else. Each category
       and custom rule marks the request (`waf_block`); an exception marks it
       too (`waf_exception`); one rule at the end blocks — or, in detect mode,
       only marks it for the access log — when it is blocked and not excepted.
       Conditions are printed raw: they are already escaped for this syntax
       (OlsDriver::wafPattern, and the 8G file's own patterns). --}}
@foreach ($waf['exceptions'] as $exception)
  {{-- The path only (bug #82): matched against the query string or the
       user agent, an exception was a password anyone could type. --}}
  RewriteCond %{REQUEST_URI} {!! $exception !!} [NC]
  RewriteRule ^ - [E=waf_exception:1]
@endforeach
@foreach ($wafRules as $category => $conditions)
@foreach ($conditions as [$variable, $pattern])
  RewriteCond {!! '%{'.$variable.'}' !!} {!! $pattern !!} [NC{{ $loop->last ? '' : ',OR' }}]
@endforeach
  RewriteRule ^ - [E=waf_block:{{ $category }}]
@endforeach
@foreach ($waf['customRules'] as $rule)
  RewriteCond %{REQUEST_URI} {!! $rule !!} [NC,OR]
  RewriteCond %{QUERY_STRING} {!! $rule !!} [NC]
  RewriteRule ^ - [E=waf_block:custom_rule]
@endforeach
  RewriteCond %{ENV:waf_exception} !=1
  RewriteCond %{ENV:waf_block} !^$
@if ($waf['mode'] === 'enforce')
  RewriteRule ^ - [E=waf_would:1,F,L]
@else
  RewriteRule ^ - [E=waf_would:1]
@endif
