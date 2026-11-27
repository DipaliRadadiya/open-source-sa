{{-- Managed by the panel. Manual edits are overwritten.
     MongoDB. `MONGO_INITDB_ROOT_*` creates the root user on an EMPTY data directory and is ignored
     afterwards. No scoped application user: the panel has no post-start step, so a site connects
     with the root credential. --}}
name: {{ $project }}

services:
  db:
    image: {{ $image }}
    @include('server.docker.databases._shared')

    environment:
      MONGO_INITDB_ROOT_USERNAME: "{{ $credentials['username'] }}"
      MONGO_INITDB_ROOT_PASSWORD: "{{ $credentials['password'] }}"
      MONGO_INITDB_DATABASE: "{{ $credentials['database'] }}"

    healthcheck:
      test: ["CMD-SHELL", "mongosh --quiet --eval 'db.adminCommand({ping:1}).ok' || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 10
@include('server.docker.databases._volumes')
