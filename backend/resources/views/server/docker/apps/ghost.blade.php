{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Ghost, with its own MySQL. Two services, and the second one is why this app
     is a template rather than an image name on the generic form.

     Derived from a hand-written file proven on a real box, with three changes:

     1. **A dedicated database user, not `root`.** The working file had Ghost
        connecting as root, which hands the app every privilege on the server's
        MySQL. `MYSQL_USER` gets rights on `MYSQL_DATABASE` and nothing else.
     2. **The volumes are `external: true`.** The panel created them and owns
        them: that is what puts them on the Docker page, gives them a Used-by
        column and makes the delete guards see them. Left project-local, Compose
        would make its own and the panel would know nothing about the data.
     3. **The shared limits partial**, so this app cannot quietly ship without a
        memory ceiling or with unbounded logs. --}}
name: {{ $project }}

services:
  ghost:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit])

    {{-- Loopback ONLY. Docker writes its own rules into the DOCKER chain ahead
         of the ones ufw manages, so `"{{ $appPort }}:{{ $containerPort }}"` —
         the form everyone writes — is reachable from the internet while the
         panel's Firewall page says the port is closed. nginx is what the world
         talks to. --}}
    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
      {{-- Ghost builds every link and redirect from this. Wrong, and the site
           serves pages whose assets and login redirect point somewhere else —
           which looks like a broken theme rather than a misconfigured URL. --}}
      url: {{ $url }}
      NODE_ENV: production
      database__client: mysql
      database__connection__host: db
      database__connection__user: ghost
      database__connection__password: {{ $secrets['GHOST_DB_PASSWORD'] }}
      database__connection__database: ghost

    volumes:
      - {{ $volumes['content'] }}:/var/lib/ghost/content

    {{-- `depends_on` orders the start; it does not wait for MySQL to be ready
         to accept connections. Ghost exits when it cannot connect, and
         `restart: unless-stopped` is what turns that into a retry loop that
         settles once MySQL is up. A healthcheck condition would be tidier and
         is a change to make deliberately, with a timeout chosen on purpose. --}}
    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    {{-- No `ports`. The database is reachable from this project's network by the
         name `db` and from nowhere else — publishing it to the host would put a
         MySQL on the server's loopback for every Ghost site created. --}}
    environment:
      MYSQL_ROOT_PASSWORD: {{ $secrets['MYSQL_ROOT_PASSWORD'] }}
      MYSQL_DATABASE: ghost
      MYSQL_USER: ghost
      MYSQL_PASSWORD: {{ $secrets['GHOST_DB_PASSWORD'] }}

    volumes:
      - {{ $volumes['db'] }}:/var/lib/mysql

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
