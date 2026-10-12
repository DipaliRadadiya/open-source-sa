{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Activepieces app and worker with a private PostgreSQL (pgvector) and Redis (RC-27). --}}
name: {{ project }}

services:
  activepieces:
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

    command: ["sh", "-c", "export AP_ENCRYPTION_KEY=$$(printf %s $$AP_ENCRYPTION_KEY_SEED | sha256sum | cut -c1-32) && unset AP_ENCRYPTION_KEY_SEED && exec ./docker-entrypoint.sh"]
    environment:
      AP_CONTAINER_TYPE: APP
      AP_ENVIRONMENT: prod
      AP_FRONTEND_URL: "{{ url }}"
      AP_ENCRYPTION_KEY_SEED: "{{ secret.AP_ENCRYPTION_KEY_SEED }}"
      AP_JWT_SECRET: "{{ secret.AP_JWT_SECRET }}"
      AP_ENGINE_EXECUTABLE_PATH: dist/packages/engine/main.js
      AP_DB_TYPE: POSTGRES
      AP_POSTGRES_HOST: postgres
      AP_POSTGRES_PORT: "5432"
      AP_POSTGRES_DATABASE: activepieces
      AP_POSTGRES_USERNAME: activepieces
      AP_POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      AP_REDIS_TYPE: STANDALONE
      AP_REDIS_HOST: redis
      AP_REDIS_PORT: "6379"
      AP_EXECUTION_MODE: SANDBOX_CODE_ONLY
      AP_WEBHOOK_TIMEOUT_SECONDS: "30"
      AP_TRIGGER_DEFAULT_POLL_INTERVAL: "5"
      AP_FLOW_TIMEOUT_SECONDS: "600"
      AP_TELEMETRY_ENABLED: "false"
      AP_TEMPLATES_SOURCE_URL: https://cloud.activepieces.com/api/v1/flow-templates

    volumes:
      - {{ volume.cache }}:/usr/src/app/cache

    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://127.0.0.1:80/api/v1/health || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 30
      start_period: 120s

  worker:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      AP_CONTAINER_TYPE: WORKER
      AP_ENVIRONMENT: prod
      AP_FRONTEND_URL: http://activepieces
      AP_JWT_SECRET: "{{ secret.AP_JWT_SECRET }}"
      AP_ENGINE_EXECUTABLE_PATH: dist/packages/engine/main.js
      AP_EXECUTION_MODE: SANDBOX_CODE_ONLY
      AP_WORKER_CONCURRENCY: "5"

    volumes:
      - {{ volume.worker-cache }}:/usr/src/app/cache

    depends_on:
      activepieces:
        condition: service_healthy

  postgres:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      POSTGRES_DB: activepieces
      POSTGRES_USER: activepieces
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U activepieces -d activepieces"]
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
  {{ volume.cache }}:
    external: true
  {{ volume.worker-cache }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
