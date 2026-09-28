{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     NocoDB, with its own Postgres.

     The connection is a URL in one variable, not a set of fields: `NC_DB` is how
     NocoDB is configured and splitting it would mean the panel assembling a
     string NocoDB then re-parses. --}}
name: {{ $project }}

services:
  nocodb:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit])

    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
      {{-- Used for invitation links and anything NocoDB emails. --}}
      NC_PUBLIC_URL: {{ $url }}
      NC_DB: "pg://db:5432?u=nocodb&p={{ $secrets['DATABASE_PASSWORD'] }}&d=nocodb"

    volumes:
      - {{ $volumes['data'] }}:/usr/app/data

    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    environment:
      POSTGRES_DB: nocodb
      POSTGRES_USER: nocodb
      POSTGRES_PASSWORD: {{ $secrets['DATABASE_PASSWORD'] }}

    volumes:
      - {{ $volumes['db'] }}:/var/lib/postgresql/data

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
