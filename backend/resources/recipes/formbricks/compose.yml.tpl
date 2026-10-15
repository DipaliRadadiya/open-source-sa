{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Formbricks 5 with its private PostgreSQL (pgvector) and Valkey, from the official docker/docker-compose.yml without the optional Hub and Cube services (RC-37). --}}
name: {{ project }}

services:
  formbricks:
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

    command:
      - sh
      - -c
      - 'export ENCRYPTION_KEY="$$(printf %s "$$ENCRYPTION_SEED" | sha256sum | cut -c1-64)" && exec /home/nextjs/start.sh'

    environment:
      WEBAPP_URL: "{{ url }}"
      NEXTAUTH_URL: "https://{{ domain }}"
      BETTER_AUTH_URL: "https://{{ domain }}"
      DATABASE_URL: "postgresql://formbricks:{{ secret.DB_PASSWORD }}@db:5432/formbricks?schema=public"
      NEXTAUTH_SECRET: "{{ secret.NEXTAUTH_SECRET }}"
      BETTER_AUTH_SECRET: "{{ secret.BETTER_AUTH_SECRET }}"
      CRON_SECRET: "{{ secret.CRON_SECRET }}"
      ENCRYPTION_SEED: "{{ secret.ENCRYPTION_SEED }}"
      REDIS_URL: redis://redis:6379
      HUB_API_URL: http://127.0.0.1:9
      HUB_API_KEY: not-configured
      CUBEJS_API_URL: http://127.0.0.1:9
      CUBEJS_API_SECRET: not-configured
      EMAIL_VERIFICATION_DISABLED: "1"
      PASSWORD_RESET_DISABLED: "1"
      TELEMETRY_DISABLED: "1"

    volumes:
      - {{ volume.uploads }}:/home/nextjs/apps/web/uploads

    depends_on:
      db:
        condition: service_healthy
      redis:
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
      POSTGRES_DB: formbricks
      POSTGRES_USER: formbricks
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "formbricks", "-d", "formbricks"]
      interval: 5s
      timeout: 5s
      retries: 30

  redis:
    image: {{ image.cache }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["valkey-server", "--appendonly", "yes", "--maxmemory-policy", "noeviction"]

    volumes:
      - {{ volume.redis }}:/data

    healthcheck:
      test: ["CMD", "valkey-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 30

volumes:
  {{ volume.uploads }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
