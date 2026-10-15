{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Infisical with a private PostgreSQL database and Redis (RC-26). --}}
name: {{ project }}

services:
  infisical:
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

    command: ["sh", "-c", "export ENCRYPTION_KEY=$$(printf %s $$ENCRYPTION_KEY_SEED | sha256sum | cut -c1-32) && unset ENCRYPTION_KEY_SEED && exec ./standalone-entrypoint.sh"]
    environment:
      NODE_ENV: production
      ENCRYPTION_KEY_SEED: "{{ secret.ENCRYPTION_KEY_SEED }}"
      AUTH_SECRET: "{{ secret.AUTH_SECRET|base64 }}"
      DB_CONNECTION_URI: "postgres://infisical:{{ secret.DB_PASSWORD }}@db:5432/infisical"
      REDIS_URL: redis://redis:6379
      SITE_URL: "{{ url }}"

    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1:8080/api/status || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 18
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
      POSTGRES_DB: infisical
      POSTGRES_USER: infisical
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U infisical -d infisical"]
      interval: 10s
      timeout: 5s
      retries: 6

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
      interval: 10s
      timeout: 5s
      retries: 6

volumes:
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
