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
    {{-- 🔴 `mariadb-admin` first, `mysqladmin` as the fallback, and the order is
         the fix rather than a style choice.

         **MariaDB 12 REMOVED `mysqladmin`.** It was renamed to `mariadb-admin` in
         11 with the old name kept as a symlink, and 12.3 dropped the symlink —
         verified on the box: `command -v mysqladmin` answers nothing, only
         `/usr/bin/mariadb-admin` exists. So this healthcheck could never pass, the
         readiness wait ran its full course, and the create rolled back with
         `not_ready`. 12.3 is the newest MariaDB in the catalog, which makes it the
         version the one-click tile offers by default — the third time in a row that
         a newest-version default was the one that could not start.

         The `||` is what makes one line serve both engines: MySQL has no
         `mariadb-admin`, so the shell answers 127 and falls through; MariaDB 11 has
         both; MariaDB 12 has only the first. Checked against mariadb:12.3 and
         mysql:8.4, both answering "mysqld is alive".

         `MYSQL_ROOT_PASSWORD` is still honoured by MariaDB 12 — also verified, so
         the variable does not need the same treatment as the binary. --}}
    healthcheck:
      test: ["CMD-SHELL", "mariadb-admin ping -h 127.0.0.1 -u root -p\"$$MYSQL_ROOT_PASSWORD\" --silent || mysqladmin ping -h 127.0.0.1 -u root -p\"$$MYSQL_ROOT_PASSWORD\" --silent"]
      interval: 10s
      timeout: 5s
      retries: 10
@include('server.docker.databases._volumes')
