{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
docRoot                   {{ $documentRoot }}
vhDomain                  {{ $serverNames[0] }}
@if (count($serverNames) > 1 || $redirects->isNotEmpty())
vhAliases                 {{ implode(', ', array_merge(array_slice($serverNames, 1), $redirects->pluck('domain')->all())) }}
@endif
enableGzip                1

errorlog $VH_ROOT/logs/error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog $VH_ROOT/logs/access.log {
  useServer               0
  rollingSize             10M
  keepDays                30
}

index {
  useServer               0
  indexFiles              index.html
}

{{-- No script handler at all. A static site that can execute PHP is a static
     site one upload away from not being static. --}}

{{-- `exp:` is OpenLiteSpeed's regex context; nginx's `~` fails the config
     test. Named directories rather than a lookahead, so .well-known stays
     reachable for certificate issuance. --}}
context exp:^/\.(git|svn|hg|bzr|env) {
  allowBrowse             0
}

{{-- A rewrite block only when there is a redirect to serve. OLS routes these
     names here as aliases, so they must be sent on explicitly or they would
     serve the site under a second name. --}}
@if ($redirects->isNotEmpty())
rewrite {
  enable                  1
@foreach ($redirects as $redirect)
  RewriteCond %{HTTP_HOST} ^{{ preg_quote($redirect->domain, '/') }}$ [NC]
  RewriteRule ^/?(.*)$ {{ $redirect->redirect_to ?: 'https://'.$domain }}/$1 [R={{ $redirect->redirect_status }},L]
@endforeach
}
@endif
