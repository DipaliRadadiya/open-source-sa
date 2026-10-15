# Managed by the panel's Dify recipe (RC-19); included by default.conf.
# Pre-rendered from Dify 1.17.1 docker/nginx/proxy.conf.template with
# NGINX_PROXY_READ_TIMEOUT and NGINX_PROXY_SEND_TIMEOUT at 3600s. The panel's
# nginx in front already set X-Forwarded-For and X-Forwarded-Proto (https), so
# they are passed through rather than rewritten to this hop's plain HTTP; the
# API trusts one proxy hop (RESPECT_XFORWARD_HEADERS_ENABLED).

proxy_set_header Host $host;
proxy_set_header X-Forwarded-For $http_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;
proxy_http_version 1.1;
proxy_set_header Connection "";
proxy_buffering off;
proxy_read_timeout 3600s;
proxy_send_timeout 3600s;
