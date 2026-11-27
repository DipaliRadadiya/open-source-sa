{{-- Managed by the panel. Manual edits are overwritten.
     Redis and Valkey share this file (same protocol, same `--requirepass`).
     The password is a command argument, not an environment variable: the official image reads none
     from the environment, so without `--requirepass` it accepts every connection that can reach it.
     `--appendonly yes`: default persistence is periodic snapshots, so a restart loses recent writes. --}}
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
