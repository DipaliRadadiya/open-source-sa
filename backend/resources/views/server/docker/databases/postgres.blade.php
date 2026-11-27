{{-- Managed by the panel. Manual edits are overwritten.
     PostgreSQL. `POSTGRES_PASSWORD`, `POSTGRES_USER` and `POSTGRES_DB` apply to an EMPTY data directory
     and are ignored afterwards, so the password here is the one the database was initialised with.
     The panel never regenerates it: file and engine would disagree. --}}
name: {{ $project }}

services:
  db:
    image: {{ $image }}
    @include('server.docker.databases._shared')

    environment:
      POSTGRES_PASSWORD: "{{ $credentials['password'] }}"
      POSTGRES_USER: "{{ $credentials['username'] }}"
      POSTGRES_DB: "{{ $credentials['database'] }}"
      {{-- Without this the data directory gets the C locale and text comparisons are byte order.
           Init-only, like the three above. --}}
      POSTGRES_INITDB_ARGS: "--encoding=UTF8"

    {{-- `pg_isready` is the engine's own answer, not a port check: the port is open while the server
         still replays WAL and refuses connections. --}}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U {{ $credentials['username'] }} -d {{ $credentials['database'] }}"]
      interval: 10s
      timeout: 5s
      retries: 10
@include('server.docker.databases._volumes')
