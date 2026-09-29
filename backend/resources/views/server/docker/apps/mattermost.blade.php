{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Mattermost Team Edition, with its own Postgres.

     Three volumes rather than one, and the reason is that Mattermost WRITES its
     own configuration: `/mattermost/config/config.json` is edited by the System
     Console, so a site whose config directory is not persisted silently loses
     every setting an admin changed the moment the container is rebuilt. `data`
     holds uploaded files and `plugins` holds anything installed from the
     marketplace — both equally unrecoverable from the database. --}}
name: {{ $project }}

services:
  mattermost:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit])

    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
      {{-- Mattermost builds invitation links, password resets and the mobile
           app's connection details from this. Wrong, and users are invited to a
           host that does not serve them. --}}
      MM_SERVICESETTINGS_SITEURL: {{ $url }}
      MM_SQLSETTINGS_DRIVERNAME: postgres
      {{-- `sslmode=disable` because this connection never leaves the project's
           own network, and `connect_timeout` so a Postgres that is still starting
           produces a retry rather than a hang. --}}
      MM_SQLSETTINGS_DATASOURCE: "postgres://mattermost:{{ $secrets['DATABASE_PASSWORD'] }}@db:5432/mattermost?sslmode=disable&connect_timeout=10"

    volumes:
      - {{ $volumes['data'] }}:/mattermost/data
      - {{ $volumes['config'] }}:/mattermost/config
      - {{ $volumes['plugins'] }}:/mattermost/plugins

    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    environment:
      POSTGRES_DB: mattermost
      POSTGRES_USER: mattermost
      POSTGRES_PASSWORD: {{ $secrets['DATABASE_PASSWORD'] }}

    volumes:
      - {{ $volumes['db'] }}:/var/lib/postgresql/data

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
