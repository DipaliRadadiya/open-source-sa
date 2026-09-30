{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     BookStack, with its own MariaDB.

     **`APP_KEY` is base64-encoded here, and that is not cosmetic.** BookStack is
     a Laravel application: the key must be `base64:` followed by exactly 32
     base64-encoded bytes, and it decrypts sessions and stored credentials. The
     panel's generator produces a 32-CHARACTER random string, which is 32 bytes,
     so encoding it here yields a valid key. Passed raw, BookStack exits at boot
     complaining about an unsupported cipher — a message that says nothing about
     the key being the problem.

     `APP_URL` is required rather than advisory: BookStack will not start without
     it, and every link and asset path is built from it.

     `/config` is the linuxserver image's home for configuration, uploads and
     generated files. The webroot holds nothing worth keeping, which is why this
     app has one volume and not two. --}}
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
      APP_KEY: "base64:{{ base64_encode($secrets['APP_KEY']) }}"
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: bookstack
      DB_USERNAME: bookstack
      DB_PASSWORD: "{{ $secrets['DATABASE_PASSWORD'] }}"

    {{-- By ROLE, not by looping `$mounts`. That list holds every volume this
         type declares, including the database's — looping it here would mount
         MariaDB's data directory into the application container as well. The
         single-container template can loop it because there is only one service
         to give them to. --}}
    volumes:
      - {{ $volumes['config'] }}:/config

    depends_on:
      - db

  db:
    image: {{ $dbImage }}
    @include('server.docker.apps.limits', ['limit' => $dbMemoryLimit])

    environment:
      MARIADB_DATABASE: bookstack
      MARIADB_USER: bookstack
      MARIADB_PASSWORD: "{{ $secrets['DATABASE_PASSWORD'] }}"
      MARIADB_ROOT_PASSWORD: "{{ $secrets['MARIADB_ROOT_PASSWORD'] }}"

    volumes:
      - {{ $volumes['db'] }}:/var/lib/mysql

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
