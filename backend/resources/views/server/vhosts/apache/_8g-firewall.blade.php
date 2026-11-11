{{-- Managed by the panel. v7's per-site file for the 8G firewall, in v7's
     place (Dipali, 2026-11-11), loaded by `IncludeOptional {site}/conf/apache/*`
     at the end of the vhost.

     The six category env vars are set server-wide by the shared file
     (Waf8GManager::ensureSharedMaps()), at post-read-request — before the
     rewrite below runs — and this site decides what to do with them: in
     enforce mode a rewrite [F] for a request any of this site's categories
     or custom rules marked and no exception excused. A rewrite rather than the
     vhost's <Directory> Require, as v7 does it, so the whole firewall lives in
     this one file. Detect mode only logs. --}}
@php
    $wafEnv = ['query_string' => 'waf_query', 'request_uri' => 'waf_uri', 'user_agent' => 'waf_agent', 'referrer' => 'waf_referer', 'cookie' => 'waf_cookie', 'method' => 'waf_method'];
    $active = collect($wafEnv)->only($waf['categories'])->values()->all();
    if ($waf['customRules'] !== []) {
        $active[] = 'waf_custom';
    }
    $action = $waf['mode'] === 'enforce' ? 'blocked' : 'detected';
@endphp
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the 8G firewall.
Include {{ config('server.waf.apache_setenvif_path') }}
@foreach ($waf['exceptions'] as $exception)
{{-- The path only (bug #82): matched against the query string or the user
     agent, an exception was a password anyone could type. --}}
SetEnvIfExpr "%{REQUEST_URI} =~ m#{!! $exception !!}#i" waf_exception
@endforeach
@foreach ($waf['customRules'] as $rule)
SetEnvIfExpr "%{REQUEST_URI} =~ m#{!! $rule !!}#i || %{QUERY_STRING} =~ m#{!! $rule !!}#i" waf_custom
@endforeach
@if ($waf['mode'] === 'enforce' && $active !== [])
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteCond %{ENV:waf_exception} !=1
@foreach ($active as $env)
RewriteCond %{ENV:{{ $env }}} !^${{ $loop->last ? '' : ' [OR]' }}
@endforeach
RewriteRule ^ - [F]
@endif
{{-- Both modes (bug #84): blocking used to leave no record of what it
     blocked. One line per active category/custom rule. `expr=`, not `env=`:
     an excepted request was never blocked and must not be logged as if it
     were. The format is combined plus which rule matched and what was done. --}}
@foreach ($wafEnv as $category => $env)
@if (in_array($category, $waf['categories'], true))
CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf={{ $category }} action={{ $action }}" "expr=-n reqenv('{{ $env }}') && -z reqenv('waf_exception')"
@endif
@endforeach
@if ($waf['customRules'] !== [])
CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=custom_rule action={{ $action }}" "expr=-n reqenv('waf_custom') && -z reqenv('waf_exception')"
@endif
