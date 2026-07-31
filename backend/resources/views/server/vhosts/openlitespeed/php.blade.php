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
  indexFiles              index.php, index.html
}

{{-- LSPHP over LSAPI. OpenLiteSpeed cannot talk to PHP-FPM at all, so there is
     no socket here to match the nginx and Apache templates — the version is
     chosen by which lsphp binary this handler points at. --}}
extprocessor lsphp{{ $lsphpVersion }} {
  type                    lsapi
  address                 uds://tmp/lshttpd/lsphp{{ $lsphpVersion }}-{{ $domain }}.sock
  maxConns                10
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               2
  path                    {{ $lsphpBinary }}
  extUser                 {{ $user }}
  extGroup                {{ $user }}
  runOnStartUp            1
  memSoftLimit            2047M
  memHardLimit            2047M
  procSoftLimit           400
  procHardLimit           500
}

scripthandler {
  add                     lsapi:lsphp{{ $lsphpVersion }} php
}

{{-- The front-controller rewrite lives here rather than in .htaccess. OLS only
     reads .htaccess when Auto Load is on and needs a full restart after every
     change to one, which would turn a permalink edit into server downtime. --}}
rewrite {
  enable                  1
  autoLoadHtaccess        0

  rules                   <<<END_rules
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /index.php?$1 [L,QSA]
  END_rules
}

{{-- Version control and dotfiles must never be served. A .git directory inside
     a web root is a full source disclosure. --}}
context ~ /\.(?!well-known) {
  location                $DOC_ROOT
  allowBrowse             0
}
