{{-- Managed by the panel. Manual edits are overwritten.

     PostgreSQL.

     `POSTGRES_PASSWORD`, `POSTGRES_USER` and `POSTGRES_DB` apply to an EMPTY data
     directory and are ignored afterwards. That is not a footnote: it means the
     password in this file is the password the database was initialised with, and
     rewriting it here would leave the file and the engine disagreeing while
     looking correct. The panel therefore never regenerates it. --}}
name: {{ $project }}

services:
  db:
    image: {{ $image }}
    @include('server.docker.databases._shared')

    environment:
      POSTGRES_PASSWORD: "{{ $credentials['password'] }}"
      POSTGRES_USER: "{{ $credentials['username'] }}"
      POSTGRES_DB: "{{ $credentials['database'] }}"
      {{-- Without this the data directory is initialised with the C locale and
           every text comparison is byte order, which surprises anyone who sorts
           names. Init-only, like the three above. --}}
      POSTGRES_INITDB_ARGS: "--encoding=UTF8"

    {{-- `pg_isready` is the engine's own answer, not a port check: the port is
         open while the server is still replaying WAL and refusing connections. --}}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U {{ $credentials['username'] }} -d {{ $credentials['database'] }}"]
      interval: 10s
      timeout: 5s
      retries: 10
@include('server.docker.databases._volumes')
