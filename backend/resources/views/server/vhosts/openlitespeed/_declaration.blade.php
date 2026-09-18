{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
{{--
    The file that DECLARES a site, and the reason httpd_config.conf no longer
    has to know a site exists.

    OpenLiteSpeed can learn about a vhost two ways: a `virtualHost` block in the
    shared config plus a `map` inside each `listener`, or a self-contained file
    that names its own listeners and domains. This panel used the first and the
    old one uses the second, and the second is better for exactly one reason —
    the shared config is the file where a mistake is not one broken site but
    every site on the box, and this shape means it is written once at install
    and never touched again.

    One `include <root>/*.conf` there picks up every file like this one.
--}}
virtualhost {{ $name }} {
    listeners                 Default@if ($certificate || $tlsFallback),DefaultHttps@endif

    {{-- Every name this site answers to, including the redirect sources: a
         redirect has to be served before it can redirect, so a name missing
         here reaches the catch-all instead and never gets the 301. --}}
    vhDomain                  {{ implode(',', array_merge($serverNames, $redirects->pluck('domain')->all())) }}

    rewrite {
        enable                  1
        {{-- The old panel enables this and sites migrated from it rely on it:
             a WordPress install with permalinks has rules in .htaccess and
             nothing else reads them. --}}
        autoLoadHtaccess        1
@if ($forceHttps)

        {{-- `!^/.well-known` is not optional. The ACME challenge has to stay
             reachable over plain HTTP or renewal stops, and the redirect then
             points confidently at a certificate that has expired. --}}
        RewriteCond %{HTTPS} !=on
        RewriteRule !^/.well-known($|/) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
@endif

        {{-- Generated rewrite rules that are not this panel's: the old panel
             writes WordPress permalink rules and its bot blocker here, and a
             vhost rewritten without this include disables both silently. --}}
        include {{ $snippetDirs['rewrites'] }}/*.conf
    }
@if ($certificate)

    vhssl {
        keyFile                 {{ $certificate->private_key_path }}
        certFile                {{ $certificate->certificate_path }}
        certChain               1
        {{-- TLS 1.2 floor. OLS spells the version set as a bitmask-style list;
             1.0 and 1.1 fail PCI checks and buy compatibility only with
             browsers that stopped getting security updates years ago. --}}
        sslProtocol             24
    }
@else

    {{-- No certificate yet, and this is still needed: without a `vhssl` block
         OLS serves this hostname the secure listener's *first* certificate, so
         every visitor to a site that has none gets somebody else's name in the
         warning. --}}
    vhssl {
        keyFile                 {{ $tlsFallback['private_key'] }}
        certFile                {{ $tlsFallback['certificate'] }}
        certChain               0
        sslProtocol             24
    }
@endif

    {{-- The body: roots, logs, handlers, the PHP processor. Included rather
         than named by `configFile`, so the pair is one unit to OpenLiteSpeed
         and the declaration stays short enough to read. --}}
    include {{ $bodyPath }}
}
