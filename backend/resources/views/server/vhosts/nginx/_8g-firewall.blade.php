{{-- Managed by the panel. v7's per-site file for the 8G firewall (Dipali, 2026-11-11:
     v7's places), loaded from the end of the site's server block by
     `include {site}/conf/nginx/*.conf`. Server-level rewrite directives run
     before any location is chosen and before auth_basic, wherever they stand
     in the block, so the order the vhost had them in is kept. --}}
{!! \App\Services\Server\WebServers\SharedConfigFile::MARKER !!}: the 8G firewall.
    {{-- 8G Firewall: checked before the AI bot block and Basic Auth — a
         request that looks like an exploit attempt should get a flat 403
         without ever being evaluated as a bot or being offered a login
         prompt. `$waf_block`/`$waf_exception` use the concatenation trick
         (`$waf_decision = "10"` means blocked-and-not-excepted) because
         nginx's `if` has no boolean AND — the same idiom used to restrict
         a staging site to one IP in every worked nginx example of it. --}}
    set $waf_block "0";
    set $waf_exception "0";
    set $waf_reason "-";
@foreach ($waf['exceptions'] as $exception)
    {{-- The path the visitor asked for — never the query (bug #82: matched
         against the query string or the user agent, an exception was a
         password anyone could type). `$request_uri`, not `$uri`: on a front
         controller `try_files` redirects internally to /index.php, this
         block runs again with `$uri` = /index.php, the exception no longer
         matched and the request was blocked anyway — so an exception never
         helped WordPress /wp-json/ or a Laravel route, the cases it exists
         for (frontend QA FS-C43). `^[^?]*` keeps the match in the path. --}}
    if ($request_uri ~* "^[^?]*{!! $exception !!}") { set $waf_exception "1"; }
@endforeach
@if ($waf['exceptions'] !== [])
    {{-- `$request_uri` is the path as sent, before nginx resolves `..`:
         `/allowed/../wp-login.php` would carry the exception to a page it
         was never meant for. No exception for a path with dot segments or
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
    {{-- Logged in both modes (bug #84): blocking used to leave no record of
         what it blocked. The format is the site's own, declared above. --}}
    set $waf_matched "0";
    if ($waf_decision = "10") { set $waf_matched "1"; }
    access_log {{ $waf['detectLogPath'] }} {{ $waf['logFormat'] }} if=$waf_matched;
@if ($waf['mode'] === 'enforce')
    if ($waf_decision = "10") {
        return 403;
    }
@endif
