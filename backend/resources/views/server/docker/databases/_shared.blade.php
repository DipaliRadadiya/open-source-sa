{{-- The parts every engine's file shares, so the four templates differ only where
     the engines genuinely differ: their environment and their command line.

     `127.0.0.1:` is the whole security posture. Docker writes its own rules into
     the DOCKER chain ahead of the ones ufw manages, so `- "5432:5432"` — the form
     every tutorial uses — puts a database on the public internet while the panel's
     Firewall page says the port is closed. A database is the worst thing on a
     server to publish by accident.

     The memory ceiling is the database default rather than the application one: an
     engine's own buffer pool is sized from what it can see, and MySQL's default
     alone is 128M.

     **The `@include` of this file must be INDENTED four spaces**, as the app
     templates already do for `apps/limits`. Blade strips the newline and the
     leading whitespace after a comment, so the first line below arrives at column
     zero and the indentation has to come from the include site instead. Written
     with the include at column zero, `restart:` lands flush left and the whole
     file stops being valid YAML — which is how this was first written, and what
     the same note on `apps/limits` is warning about. --}}
    restart: unless-stopped

    ports:
      - "127.0.0.1:{{ $port }}:{{ $enginePort }}"

    mem_limit: {{ $memoryLimit }}

    {{-- Bounded, and to the local journal rather than a file the panel does not
         rotate. Docker's json-file driver has NO max size by default: a database
         logging every slow query fills the disk, and the first symptom is every
         site on the box failing to write. --}}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    volumes:
      - {{ $volume }}:{{ $dataPath }}
@if ($network !== null)

    {{-- Named with `external: true` below, because Compose cannot discover a
         network the panel created: without it this joins a differently-prefixed
         network of its own and the sites that were meant to reach it cannot. --}}
    networks:
      {{ $network }}:
        aliases:
          - {{ $name }}
@endif
