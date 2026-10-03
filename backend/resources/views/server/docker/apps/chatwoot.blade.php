{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Chatwoot: a Rails app, a Sidekiq worker, pgvector and Redis.

     **Why four services.** Sidekiq is not optional decoration — every outgoing
     email, webhook, automation rule and scheduled job runs there, so a site with
     only the web container serves pages and silently does none of that. Postgres
     is `pgvector` because Chatwoot's schema declares the `vector` extension and
     plain Postgres refuses the migration. Redis holds Sidekiq's queue and the
     installation-onboarding flag the panel claims the instance through.

     **Why the web service migrates.** Upstream's `rails.sh` waits for Postgres,
     runs `bundle install` and execs — nothing in the image migrates. A first boot
     without `db:chatwoot_prepare` is a Rails app answering 500 against an empty
     database. It is idempotent, so it is also the upgrade path. A one-shot
     service would have been cleaner except that every service here carries
     `restart: unless-stopped`, under which a container exiting 0 restarts
     forever.

     **Only the web service publishes.** Postgres and Redis are reachable on the
     project network by name and nowhere else — upstream's own compose publishes
     5432 and 6379 on the host, which on a panel-managed box would be a database
     nobody is watching. --}}
name: {{ $project }}

services:
  rails:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit, 'cpus' => $cpuLimit])

    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
      RAILS_ENV: production
      NODE_ENV: production
      INSTALLATION_ENV: docker
      {{-- Chatwoot builds invitation links, password resets and the widget's
           script tag from this. --}}
      FRONTEND_URL: "{{ $url }}"
      {{-- Signs every session cookie. Stored per site: regenerating it logs
           everyone out. --}}
      SECRET_KEY_BASE: "{{ $secrets['SECRET_KEY_BASE'] }}"
      POSTGRES_HOST: db
      POSTGRES_USERNAME: chatwoot
      POSTGRES_PASSWORD: "{{ $secrets['POSTGRES_PASSWORD'] }}"
      POSTGRES_DATABASE: chatwoot
      REDIS_URL: "redis://:{{ $secrets['REDIS_PASSWORD'] }}@redis:6379"
      {{-- The onboarding flow below creates the one administrator this install
           needs. Left open, the signup page is a second door onto the same
           instance for anyone who finds the URL. --}}
      ENABLE_ACCOUNT_SIGNUP: "false"

    {{-- The entrypoint waits for Postgres; the migration has to come after that
         wait and before the server, which is why both are in the command. --}}
    entrypoint: docker/entrypoints/rails.sh
    command: ["sh", "-c", "bundle exec rails db:chatwoot_prepare && bundle exec rails s -p {{ $containerPort }} -b 0.0.0.0"]

    volumes:
      - {{ $volumes['storage'] }}:/app/storage

    depends_on:
      - db
      - redis

  sidekiq:
    image: {{ $image }}
    {{-- Its own ceiling, not the user's figure: the memory field names the thing
         they deployed, and charging the worker the same number again would make
         a 2g choice mean 4g of box. A worker holds one job at a time and 1g is
         upstream's own guidance. --}}
    @include('server.docker.apps.limits', ['limit' => '1g'])

    environment:
      RAILS_ENV: production
      NODE_ENV: production
      INSTALLATION_ENV: docker
      FRONTEND_URL: "{{ $url }}"
      SECRET_KEY_BASE: "{{ $secrets['SECRET_KEY_BASE'] }}"
      POSTGRES_HOST: db
      POSTGRES_USERNAME: chatwoot
      POSTGRES_PASSWORD: "{{ $secrets['POSTGRES_PASSWORD'] }}"
      POSTGRES_DATABASE: chatwoot
      REDIS_URL: "redis://:{{ $secrets['REDIS_PASSWORD'] }}@redis:6379"

    command: ["bundle", "exec", "sidekiq", "-C", "config/sidekiq.yml"]

    {{-- The same ActiveStorage directory as the web service: a job that renders
         an attachment writes where the web service will read it. --}}
    volumes:
      - {{ $volumes['storage'] }}:/app/storage

    depends_on:
      - db
      - redis

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    environment:
      POSTGRES_DB: chatwoot
      POSTGRES_USER: chatwoot
      {{-- Applied only to an EMPTY data directory. The volume outlives the
           container, so this value is generated once per site and never
           regenerated — a new one against existing data is an auth failure with
           a correct-looking file. --}}
      POSTGRES_PASSWORD: "{{ $secrets['POSTGRES_PASSWORD'] }}"

    volumes:
      - {{ $volumes['db'] }}:/var/lib/postgresql/data

  redis:
    image: {{ $redisImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    {{-- The password is written into the command rather than read from the
         environment: upstream's form is `--requirepass "$REDIS_PASSWORD"`, and a
         `$` in a compose file is Compose's own interpolation, so that form
         resolves to an EMPTY password here and the queue ends up open on the
         project network. --}}
    command: ["redis-server", "--requirepass", "{{ $secrets['REDIS_PASSWORD'] }}"]

    volumes:
      - {{ $volumes['redis'] }}:/data

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
