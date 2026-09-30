{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     WordPress, with its own MariaDB.

     **`WORDPRESS_CONFIG_EXTRA` is the load-bearing part of this file.** Two
     problems it solves, neither of which is visible until somebody loads the site:

     1. nginx terminates TLS and proxies to this container over plain HTTP, so PHP
        sees no `HTTPS` in `$_SERVER`. WordPress builds every asset URL as `http://`
        — which the browser blocks as mixed content — and if `siteurl` in the
        database says `https`, the disagreement turns every request into a redirect
        loop. The container vhost sends `X-Forwarded-Proto`, so this reads it.

     2. `WP_HOME` and `WP_SITEURL` come from the panel's domain rather than from the
        database, so changing the site's domain follows through instead of leaving
        WordPress redirecting to the old host — the lockout everybody has had once.
        `WORDPRESS_SITE_URL` is rewritten by `syncUrl()` when the domain moves.

     Salts are NOT set here. The image's entrypoint generates the eight keys from
     /dev/urandom when they are absent and writes them into `wp-config.php`, which
     lives in the volume — unique per site and stable across restarts. --}}
name: {{ $project }}

services:
  app:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit, 'cpus' => $cpuLimit])

    {{-- Loopback ONLY. Docker writes its own rules into the DOCKER chain ahead of
         the ones ufw manages, so the form everyone writes is reachable from the
         internet while the panel's Firewall page says the port is closed. --}}
    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
@foreach ($environment as $key => $value)
      {{ $key }}: "{{ $value }}"
@endforeach
      WORDPRESS_DB_PASSWORD: "{{ $secrets['DATABASE_PASSWORD'] }}"
      {{-- `$$` and not `$`. Compose interpolates `$NAME` in its own file, so a PHP
           variable written plainly is REPLACED — silently, with no warning: the
           file said `$_SERVER['HTTP_X_FORWARDED_PROTO']` and the container
           received `['HTTP_X_FORWARDED_PROTO']`, so wp-config's eval() died on
           "Cannot use isset() on the result of an expression" and every request
           was a 500. `$$` is compose's escape and reaches PHP as one `$`.

           Measured on a real box. Nothing in the rendered YAML looks wrong, which
           is why the test for this reads the CONTAINER's environment. --}}
      WORDPRESS_CONFIG_EXTRA: |
        if (isset($$_SERVER['HTTP_X_FORWARDED_PROTO']) && $$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $$_SERVER['HTTPS'] = 'on';
        }
        if (getenv('WORDPRESS_SITE_URL')) {
            define('WP_HOME', getenv('WORDPRESS_SITE_URL'));
            define('WP_SITEURL', getenv('WORDPRESS_SITE_URL'));
        }

    volumes:
      - {{ $volumes['app'] }}:/var/www/html

    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    environment:
      MARIADB_DATABASE: wordpress
      MARIADB_USER: wordpress
      MARIADB_PASSWORD: "{{ $secrets['DATABASE_PASSWORD'] }}"
      MARIADB_ROOT_PASSWORD: "{{ $secrets['MARIADB_ROOT_PASSWORD'] }}"

    volumes:
      - {{ $volumes['db'] }}:/var/lib/mysql

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
