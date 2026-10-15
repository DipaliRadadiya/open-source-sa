{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Hasura GraphQL Engine (CE v2) with a private PostgreSQL database (RC-31). --}}
name: {{ project }}

services:
  hasura:
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

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      HASURA_GRAPHQL_DATABASE_URL: "postgres://hasura:{{ secret.DB_PASSWORD }}@db:5432/hasura"
      HASURA_GRAPHQL_METADATA_DATABASE_URL: "postgres://hasura:{{ secret.DB_PASSWORD }}@db:5432/hasura"
      HASURA_GRAPHQL_ADMIN_SECRET: "{{ secret.ADMIN_SECRET }}"
      HASURA_GRAPHQL_ENABLE_CONSOLE: "true"
      HASURA_GRAPHQL_CONSOLE_ASSETS_DIR: /srv/console-assets
      HASURA_GRAPHQL_DEV_MODE: "false"
      HASURA_GRAPHQL_ENABLE_TELEMETRY: "false"

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:8080/healthz"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 30s

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
      POSTGRES_DB: hasura
      POSTGRES_USER: hasura
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "hasura", "-d", "hasura"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.db }}:
    external: true
