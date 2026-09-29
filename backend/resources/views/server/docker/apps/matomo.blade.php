{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Matomo, with its own MariaDB.

     The `MATOMO_DATABASE_*` variables do not configure Matomo — they PRE-FILL its
     installation wizard, which is the only thing that writes `config.ini.php`.
     So the first visit still walks through setup, and the database page arrives
     already answered. Nothing here can skip the wizard, and a card that claimed
     to would be lying about where the superuser comes from.

     `/var/www/html` is a volume because Matomo writes into its own webroot:
     `config/config.ini.php`, downloaded plugins, and the GeoIP database. A site
     without it loses its configuration on the next rebuild and walks through the
     installer again, against a database that is already populated. --}}
name: {{ $project }}

services:
  matomo:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit])

    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
      MATOMO_DATABASE_HOST: db
      MATOMO_DATABASE_ADAPTER: mysql
      MATOMO_DATABASE_DBNAME: matomo
      MATOMO_DATABASE_USERNAME: matomo
      MATOMO_DATABASE_PASSWORD: {{ $secrets['DATABASE_PASSWORD'] }}

    volumes:
      - {{ $volumes['app'] }}:/var/www/html

    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    {{-- Matomo's own recommendation for its schema. Without it a site with
         long-tail page URLs hits MariaDB's index-length limit during an upgrade
         rather than at install, which is the worst time to find out. --}}
    command: --max-allowed-packet=64MB

    environment:
      MARIADB_DATABASE: matomo
      MARIADB_USER: matomo
      MARIADB_PASSWORD: {{ $secrets['DATABASE_PASSWORD'] }}
      MARIADB_ROOT_PASSWORD: {{ $secrets['MARIADB_ROOT_PASSWORD'] }}

    volumes:
      - {{ $volumes['db'] }}:/var/lib/mysql

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
