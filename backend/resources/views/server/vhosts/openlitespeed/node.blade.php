{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     OpenLiteSpeed proxies by declaring the backend as an external application
     of type `proxy` and pointing a context at it — not with a `proxy_pass`
     style directive. Source: docs.openlitespeed.org/config/reverseproxy. --}}
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

{{-- The backend, as an external application. `address` carries the scheme
     here, unlike the websocket block below. --}}
extprocessor {{ $appName }} {
  type                    proxy
  address                 http://127.0.0.1:{{ $appPort }}
  maxConns                100
  initTimeout             60
  retryTimeout            0
  respBuffer              0
}

{{-- Everything goes to the app. A Node application owns its own routing, so
     there is no static context ahead of this. --}}
context / {
  type                    proxy
  handler                 {{ $appName }}
  addDefaultCharset       off
}

{{-- WebSockets are a separate block in OpenLiteSpeed, not a header dance:
     traffic carrying the upgrade request is matched here and everything else
     falls through to the context above. Note the address has **no scheme** —
     OLS wants host:port. It does not support a WSS backend, which is fine
     because the client's TLS terminates here. --}}
websocket / {
  address                 127.0.0.1:{{ $appPort }}
}
