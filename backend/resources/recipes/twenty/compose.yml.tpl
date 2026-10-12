{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Twenty v2.46.0 with its worker and private PostgreSQL/Redis (RC-18). --}}
name: {{ project }}

services:
  server:
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
      NODE_PORT: "3000"
      SERVER_URL: "{{ url }}"
      PG_DATABASE_URL: "postgres://postgres:{{ secret.PG_PASSWORD }}@db:5432/default"
      REDIS_URL: redis://redis:6379
      DISABLE_DB_MIGRATIONS: "false"
      DISABLE_CRON_JOBS_REGISTRATION: "false"
      STORAGE_TYPE: local
      APP_SECRET: "{{ secret.APP_SECRET }}"
      ENCRYPTION_KEY: "{{ secret.ENCRYPTION_KEY }}"
      IS_MULTIWORKSPACE_ENABLED: "false"

    volumes:
      - {{ volume.server-local-data }}:/app/packages/twenty-server/.local-storage

    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "curl", "--fail", "http://localhost:3000/healthz"]
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 300s

  worker:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["yarn", "worker:prod"]
    environment:
      SERVER_URL: "{{ url }}"
      PG_DATABASE_URL: "postgres://postgres:{{ secret.PG_PASSWORD }}@db:5432/default"
      REDIS_URL: redis://redis:6379
      DISABLE_DB_MIGRATIONS: "true"
      DISABLE_CRON_JOBS_REGISTRATION: "true"
      STORAGE_TYPE: local
      APP_SECRET: "{{ secret.APP_SECRET }}"
      ENCRYPTION_KEY: "{{ secret.ENCRYPTION_KEY }}"
      IS_MULTIWORKSPACE_ENABLED: "false"

    volumes:
      - {{ volume.server-local-data }}:/app/packages/twenty-server/.local-storage

    depends_on:
      db:
        condition: service_healthy
      server:
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
      POSTGRES_DB: default
      POSTGRES_PASSWORD: "{{ secret.PG_PASSWORD }}"
      POSTGRES_USER: postgres

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "postgres", "-h", "localhost", "-d", "postgres"]
      interval: 5s
      timeout: 5s
      retries: 10

  redis:
    image: {{ image.cache }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["--maxmemory-policy", "noeviction"]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 10

volumes:
  {{ volume.server-local-data }}:
    external: true
  {{ volume.db }}:
    external: true
