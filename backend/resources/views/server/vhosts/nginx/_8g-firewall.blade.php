{{-- Managed by the panel. Per-site file for the 8G firewall, loaded from the end of the site's server block
     by `include {site}/conf/nginx/*.conf`. Server-level rewrite directives run before any location is
     chosen and before auth_basic, wherever they stand in the block, so the order is kept. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the 8G firewall.
    {{-- 8G Firewall: checked before the AI bot block and Basic Auth, so a request that looks like an exploit
         attempt gets a flat 403 without being evaluated as a bot or offered a login prompt.
         `$waf_block`/`$waf_exception` use the concatenation trick (`$waf_decision = "10"` means blocked and
         not excepted) because nginx's `if` has no boolean AND. --}}
    set $waf_block "0";
    set $waf_exception "0";
    set $waf_reason "-";
@foreach ($waf['exceptions'] as $exception)
    {{-- The path the visitor asked for, never the query: matched against the query string or the user
         agent, an exception would be a password anyone could type. `$request_uri`, not `$uri`: after a
         front controller's `try_files` redirects internally to /index.php, `$uri` is /index.php and the
         exception would no longer match. `^[^?]*` keeps the match in the path. --}}
    if ($request_uri ~* "^[^?]*{!! $exception !!}") { set $waf_exception "1"; }
@endforeach
@if ($waf['exceptions'] !== [])
    {{-- `$request_uri` is the path as sent, before nginx resolves `..`: `/allowed/../wp-login.php` would
         carry the exception to a page it was never meant for. No exception for a path with dot segments or
         doubled slashes, encoded or not. --}}
    if ($request_uri ~* "^[^?]*(/\.\.|%2e%2e|\.%2e|%2e\.|//|%2f)") { set $waf_exception "0"; }
@endif
@if (in_array('query_string', $waf['categories'], true))
    if ($bad_querystring_ng) { set $waf_block "1"; set $waf_reason "query_string"; }
@endif
@if (in_array('request_uri', $waf['categories'], true))
    if ($bad_request_ng) { set $waf_block "1"; set $waf_reason "request_uri"; }
@endif
@if (in_array('user_agent', $waf['categories'], true))
    if ($bad_bot_ng) { set $waf_block "1"; set $waf_reason "user_agent"; }
@endif
@if (in_array('referrer', $waf['categories'], true))
    if ($bad_referer_ng) { set $waf_block "1"; set $waf_reason "referrer"; }
@endif
@if (in_array('cookie', $waf['categories'], true))
    if ($bad_cookie_ng) { set $waf_block "1"; set $waf_reason "cookie"; }
@endif
@if (in_array('method', $waf['categories'], true))
    if ($not_allowed_method_ng) { set $waf_block "1"; set $waf_reason "method"; }
@endif
@foreach ($waf['customRules'] as $rule)
    if ($request_uri ~* "{!! $rule !!}") { set $waf_block "1"; set $waf_reason "custom_rule"; }
    if ($args ~* "{!! $rule !!}") { set $waf_block "1"; set $waf_reason "custom_rule"; }
@endforeach
    set $waf_decision "${waf_block}${waf_exception}";
    {{-- Logged in both modes, in the site's own log format declared above. --}}
    set $waf_matched "0";
    if ($waf_decision = "10") { set $waf_matched "1"; }
    access_log {{ $waf['detectLogPath'] }} {{ $waf['logFormat'] }} if=$waf_matched;
@if ($waf['mode'] === 'enforce')
    if ($waf_decision = "10") {
        return 403;
    }
@endif
