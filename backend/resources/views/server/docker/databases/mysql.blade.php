{{-- Managed by the panel. Manual edits are overwritten.

     MySQL and MariaDB. One file: MariaDB's image accepts the `MYSQL_*` variables
     as well as its own, and everything else about the two is identical here.

     Every variable below applies to an EMPTY data directory and is ignored
     afterwards, so the password in this file is the one the database was
     initialised with. Regenerating it would leave the file and the engine
     disagreeing while looking correct — which is the bug the panel already hit
     once with Postgres and Matomo. --}}
name: {{ $project }}

services:
  db:
    image: {{ $image }}
    @include('server.docker.databases._shared')

    environment:
      {{-- root's password is stored and shown separately from the application
           user's. The engine needs root to create the schema; nothing else
           should have it. --}}
      MYSQL_ROOT_PASSWORD: "{{ $credentials['root_password'] }}"
      MYSQL_DATABASE: "{{ $credentials['database'] }}"
      MYSQL_USER: "{{ $credentials['username'] }}"
      MYSQL_PASSWORD: "{{ $credentials['password'] }}"

    {{-- The engine's own readiness, not the port's. --}}
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -u root -p\"$$MYSQL_ROOT_PASSWORD\" --silent"]
      interval: 10s
      timeout: 5s
      retries: 10
@include('server.docker.databases._volumes')
