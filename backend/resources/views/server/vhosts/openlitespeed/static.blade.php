{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
docRoot                   {{ $documentRoot }}
vhDomain                  {{ $domain }}
vhAliases                 www.{{ $domain }}
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

context ~ /\.(?!well-known) {
  location                $DOC_ROOT
  allowBrowse             0
}
