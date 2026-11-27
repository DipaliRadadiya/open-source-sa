{{-- Managed by the panel. Per-site file for the 8G firewall, loaded by
     `IncludeOptional {site}/conf/apache/*` at the end of the vhost.
     The six category env vars are set server-wide by the shared file (Waf8GManager::ensureSharedMaps())
     at post-read-request, before the rewrite below runs; this site decides what to do with them. In
     enforce mode a rewrite [F] blocks a request that any of this site's categories or custom rules
     marked and no exception excused. A rewrite rather than the vhost's <Directory> Require, so the
     whole firewall lives in this one file. Detect mode only logs. --}}
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
{{-- The path only: matched against the query string or the user agent, an exception would be a
     password anyone could type. --}}
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
{{-- Both modes, so blocking leaves a record. One line per active category/custom rule. `expr=`, not
     `env=`: an excepted request was never blocked and must not be logged as if it were. Format:
     combined plus which rule matched and what was done. --}}
@foreach ($wafEnv as $category => $env)
@if (in_array($category, $waf['categories'], true))
CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf={{ $category }} action={{ $action }}" "expr=-n reqenv('{{ $env }}') && -z reqenv('waf_exception')"
@endif
@endforeach
@if ($waf['customRules'] !== [])
CustomLog {{ $waf['detectLogPath'] }} "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" waf=custom_rule action={{ $action }}" "expr=-n reqenv('waf_custom') && -z reqenv('waf_exception')"
@endif
