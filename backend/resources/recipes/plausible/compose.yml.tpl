{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Plausible Analytics CE with PostgreSQL and ClickHouse, from the official compose file (RC-32). --}}
name: {{ project }}

services:
  plausible:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: {{ memory_limit }}
{{#if cpu_limit}}
    cpus: {{ cpu_limit }}
{{/if}}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: sh -c "/entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run"

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      BASE_URL: "{{ url }}"
      SECRET_KEY_BASE: "{{ secret.SECRET_KEY_BASE }}{{ secret.SECRET_KEY_BASE_2 }}"
      TOTP_VAULT_KEY: "{{ secret.TOTP_VAULT_KEY|base64 }}"
      DISABLE_REGISTRATION: invite_only
      HTTP_PORT: "8000"
      DATABASE_URL: "postgres://plausible:{{ secret.DB_PASSWORD }}@db:5432/plausible_db"
      CLICKHOUSE_DATABASE_URL: http://events_db:8123/plausible_events_db
      TMPDIR: /var/lib/plausible/tmp

    volumes:
      - {{ volume.data }}:/var/lib/plausible

    ulimits:
      nofile:
        soft: 65535
        hard: 65535

    depends_on:
      db:
        condition: service_healthy
      events_db:
        condition: service_healthy

  db:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      POSTGRES_DB: plausible_db
      POSTGRES_USER: plausible
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "plausible", "-d", "plausible_db"]
      interval: 5s
      timeout: 5s
      retries: 12
      start_period: 1m

  events_db:
    image: {{ image.events }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      CLICKHOUSE_SKIP_USER_SETUP: "1"

    volumes:
      - {{ volume.events }}:/var/lib/clickhouse
      - {{ volume.events-logs }}:/var/log/clickhouse-server
      - {{ site_root }}/etc/clickhouse-server/config.d:/etc/clickhouse-server/config.d:ro
      - {{ site_root }}/etc/clickhouse-server/users.d:/etc/clickhouse-server/users.d:ro

    ulimits:
      nofile:
        soft: 262144
        hard: 262144

    healthcheck:
      test: ["CMD-SHELL", "wget --no-verbose --tries=1 -O - http://127.0.0.1:8123/ping || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 1m

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.events }}:
    external: true
  {{ volume.events-logs }}:
    external: true
