{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Metabase, with its own Postgres.

     **The database is not optional here even though Metabase starts without
     one.** Left unset it uses an embedded H2 file, which upstream documents as
     unsuitable for production and which cannot be backed up while running. A
     one-click that quietly gives somebody H2 is a one-click that loses their
     dashboards.

     No site URL variable: Metabase asks for it in its own setup wizard and
     stores it in the database, so passing one would be a second source for a
     value the app already owns. --}}
name: {{ $project }}

services:
  metabase:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit])

    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
      MB_DB_TYPE: postgres
      MB_DB_HOST: db
      MB_DB_PORT: 5432
      MB_DB_DBNAME: metabase
      MB_DB_USER: metabase
      MB_DB_PASS: {{ $secrets['DATABASE_PASSWORD'] }}

    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    environment:
      POSTGRES_DB: metabase
      POSTGRES_USER: metabase
      POSTGRES_PASSWORD: {{ $secrets['DATABASE_PASSWORD'] }}

    volumes:
      - {{ $volumes['db'] }}:/var/lib/postgresql/data

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
