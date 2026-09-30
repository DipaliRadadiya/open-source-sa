{{-- Managed by the panel. Manual edits are overwritten.

     Redis, and Valkey — a fork with the same wire protocol and the same
     `--requirepass`, so the same file.

     **The password is a command argument, not an environment variable**, and that
     is the one thing to know here: the official image reads no password from the
     environment, so an image started without `--requirepass` accepts every
     connection that can reach it. Bound to loopback that is survivable; on a
     shared network it is not, and this is a cache people put session tokens in.

     `--appendonly yes` because the volume is pointless otherwise: the default
     persistence is periodic snapshots, so a container restart loses whatever
     arrived since the last one. --}}
name: {{ $project }}

services:
  db:
    image: {{ $image }}
    @include('server.docker.databases._shared')

    command: ["redis-server", "--requirepass", "{{ $credentials['password'] }}", "--appendonly", "yes"]

    healthcheck:
      test: ["CMD-SHELL", "redis-cli -a \"{{ $credentials['password'] }}\" ping | grep -q PONG"]
      interval: 10s
      timeout: 5s
      retries: 10
@include('server.docker.databases._volumes')
