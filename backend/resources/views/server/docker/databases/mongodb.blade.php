{{-- Managed by the panel. Manual edits are overwritten.

     MongoDB.

     `MONGO_INITDB_ROOT_*` creates the root user on an EMPTY data directory and is
     ignored afterwards — the same init-only rule as every other engine here.

     Mongo has no separate application user: the root credential is what the panel
     stores and what a site connects with. Creating a scoped user would need a
     command run after the server is up, and the panel has no post-start step. That
     is a real limitation rather than an oversight, and it is why the connection
     details name the root user. --}}
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
