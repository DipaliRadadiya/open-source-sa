{{-- Managed by the panel. Manual edits are overwritten.
     MySQL and MariaDB in one file: MariaDB's image accepts the `MYSQL_*` variables too.
     Every variable applies to an EMPTY data directory and is ignored afterwards, so the password here
     is the one the database was initialised with. Never regenerate it: file and engine would disagree. --}}
name: {{ $project }}

services:
  db:
    image: {{ $image }}
    @include('server.docker.databases._shared')

    environment:
      {{-- root's password is stored and shown separately from the application user's: the engine needs
           root to create the schema, nothing else should have it. --}}
      MYSQL_ROOT_PASSWORD: "{{ $credentials['root_password'] }}"
      MYSQL_DATABASE: "{{ $credentials['database'] }}"
      MYSQL_USER: "{{ $credentials['username'] }}"
      MYSQL_PASSWORD: "{{ $credentials['password'] }}"

    {{-- The engine's own readiness, not the port's. --}}
    {{-- `mariadb-admin` first, `mysqladmin` as the fallback: MariaDB 12.3 removed the `mysqladmin`
         symlink, so the old name alone never passes. The `||` lets one line serve both engines (MySQL has
         no `mariadb-admin`, so the shell falls through). --}}
    healthcheck:
      test: ["CMD-SHELL", "mariadb-admin ping -h 127.0.0.1 -u root -p\"$$MYSQL_ROOT_PASSWORD\" --silent || mysqladmin ping -h 127.0.0.1 -u root -p\"$$MYSQL_ROOT_PASSWORD\" --silent"]
      interval: 10s
      timeout: 5s
      retries: 10
@include('server.docker.databases._volumes')
