{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Strapi, with its own Postgres.

     Two things here differ from Ghost and are worth knowing before editing:

     1. **Four secrets, not one.** `APP_KEYS` signs sessions, `JWT_SECRET` signs
        user tokens, `ADMIN_JWT_SECRET` signs admin tokens and `API_TOKEN_SALT`
        derives API tokens. Strapi starts without them in development and refuses
        in production — and a shipped default would mean every panel-installed
        Strapi in the world shared its signing keys.
     2. **Postgres, not SQLite.** Strapi's SQLite mode keeps the database inside
        the app directory, which for a container means inside the image layer
        unless it is mounted out. A second service is clearer than a volume at a
        path upstream may move. --}}
name: {{ $project }}

services:
  strapi:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit])

    {{-- Loopback ONLY — see the note in the generic template about Docker
         writing its own rules ahead of ufw's. --}}
    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
      NODE_ENV: production
      {{-- Strapi builds admin-panel URLs from this. --}}
      URL: {{ $url }}
      DATABASE_CLIENT: postgres
      DATABASE_HOST: db
      DATABASE_PORT: 5432
      DATABASE_NAME: strapi
      DATABASE_USERNAME: strapi
      DATABASE_PASSWORD: {{ $secrets['DATABASE_PASSWORD'] }}
      APP_KEYS: {{ $secrets['APP_KEYS'] }}
      JWT_SECRET: {{ $secrets['JWT_SECRET'] }}
      ADMIN_JWT_SECRET: {{ $secrets['ADMIN_JWT_SECRET'] }}
      API_TOKEN_SALT: {{ $secrets['API_TOKEN_SALT'] }}

    volumes:
      {{-- Uploads only. The rest of a Strapi site is its code, which is in the
           image, and its data, which is in Postgres. --}}
      - {{ $volumes['uploads'] }}:/opt/app/public/uploads

    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    {{-- No `ports`: reachable as `db` from this project and nowhere else. --}}
    environment:
      POSTGRES_DB: strapi
      POSTGRES_USER: strapi
      POSTGRES_PASSWORD: {{ $secrets['DATABASE_PASSWORD'] }}

    volumes:
      - {{ $volumes['db'] }}:/var/lib/postgresql/data

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
