{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Docmost with a private PostgreSQL database and Redis (RC-30). --}}
name: {{ project }}

services:
  docmost:
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
      APP_URL: "{{ url }}"
      APP_SECRET: "{{ secret.APP_SECRET }}"
      DATABASE_URL: "postgresql://docmost:{{ secret.DB_PASSWORD }}@db:5432/docmost"
      REDIS_URL: redis://redis:6379
      STORAGE_DRIVER: local
      DISABLE_TELEMETRY: "true"

    volumes:
      - {{ volume.storage }}:/app/data/storage

    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:3000/api/health/live"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 60s

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
      POSTGRES_DB: docmost
      POSTGRES_USER: docmost
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "docmost", "-d", "docmost"]
      interval: 5s
      timeout: 5s
      retries: 12

  redis:
    image: {{ image.cache }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["redis-server", "--appendonly", "yes", "--maxmemory-policy", "noeviction"]

    volumes:
      - {{ volume.redis }}:/data

    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.storage }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
