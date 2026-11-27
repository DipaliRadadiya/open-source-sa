{{-- Parts every engine's file shares, so the templates differ only in environment and command line.
     `127.0.0.1:` is the whole security posture: Docker writes its own rules ahead of ufw's, so a plain
     `"5432:5432"` puts a database on the public internet while the Firewall page says closed.
     Memory ceiling: the instance's own, falling back to the database default rather than the
     application one (an engine sizes its buffer pool from what it sees).
     `cpus` is rendered only when set: an empty `cpus:` is read as `0`, which means no limit.
     The `@include` of this file must be INDENTED four spaces, as for `apps/limits`: the first line
     below arrives at column zero, and flush-left `restart:` is invalid YAML. --}}
    restart: unless-stopped

    ports:
      - "127.0.0.1:{{ $port }}:{{ $enginePort }}"

    mem_limit: {{ $memoryLimit }}
@if ($cpuLimit !== null)
    cpus: {{ $cpuLimit }}
@endif

    {{-- Bounded, to the local journal: Docker's json-file driver has no max size by default, so a
         database logging every slow query fills the disk. --}}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    volumes:
      - {{ $volume }}:{{ $dataPath }}
@if ($network !== null)

    {{-- Named with `external: true` below: Compose cannot discover a network the panel created, and
         without it joins a differently prefixed network of its own. --}}
    networks:
      {{ $network }}:
        aliases:
          - {{ $name }}
@endif
