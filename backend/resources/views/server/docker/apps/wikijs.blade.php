{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Wiki.js, with its own Postgres.

     One volume, for the database only: Wiki.js keeps pages, uploads and its
     configuration in Postgres, so there is no application directory worth
     persisting. An app with nothing of its own on disk is the exception here,
     which is why it is said out loud rather than looking like an omission. --}}
name: {{ $project }}

services:
  wiki:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit, 'cpus' => $cpuLimit])

    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
      DB_TYPE: postgres
      DB_HOST: db
      DB_PORT: 5432
      DB_NAME: wiki
      DB_USER: wiki
      DB_PASS: {{ $secrets['DATABASE_PASSWORD'] }}

    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    environment:
      POSTGRES_DB: wiki
      POSTGRES_USER: wiki
      POSTGRES_PASSWORD: {{ $secrets['DATABASE_PASSWORD'] }}

    volumes:
      - {{ $volumes['db'] }}:/var/lib/postgresql/data

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
