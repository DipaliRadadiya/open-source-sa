{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Langfuse 4.57.0: web, worker, PostgreSQL, ClickHouse, Redis and Silo object storage,
     from the official docker-compose.yml (RC-33). --}}
name: {{ project }}

services:
  langfuse-web:
    image: {{ image.web }}
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

    command: ["sh", "-c", "export ENCRYPTION_KEY=$$(printf %s $$ENCRYPTION_KEY_SEED | sha256sum | cut -c1-64) && unset ENCRYPTION_KEY_SEED && exec node ./web/server.js --keepAliveTimeout 110000"]

    environment:
      NEXTAUTH_URL: "{{ url }}"
      NEXTAUTH_SECRET: "{{ secret.NEXTAUTH_SECRET }}"
      HOSTNAME: 0.0.0.0
      DATABASE_URL: "postgresql://postgres:{{ secret.DB_PASSWORD }}@postgres:5432/postgres"
      SALT: "{{ secret.SALT }}"
      ENCRYPTION_KEY_SEED: "{{ secret.ENCRYPTION_KEY_SEED }}"
      AUTH_DISABLE_SIGNUP: "true"
      CLICKHOUSE_MIGRATION_URL: clickhouse://clickhouse:9000
      CLICKHOUSE_URL: http://clickhouse:8123
      CLICKHOUSE_USER: clickhouse
      CLICKHOUSE_PASSWORD: "{{ secret.CLICKHOUSE_PASSWORD }}"
      CLICKHOUSE_CLUSTER_ENABLED: "false"
      LANGFUSE_S3_EVENT_UPLOAD_BUCKET: langfuse
      LANGFUSE_S3_EVENT_UPLOAD_REGION: auto
      LANGFUSE_S3_EVENT_UPLOAD_ACCESS_KEY_ID: langfuse
      LANGFUSE_S3_EVENT_UPLOAD_SECRET_ACCESS_KEY: "{{ secret.MINIO_PASSWORD }}"
      LANGFUSE_S3_EVENT_UPLOAD_ENDPOINT: http://minio:9000
      LANGFUSE_S3_EVENT_UPLOAD_FORCE_PATH_STYLE: "true"
      LANGFUSE_S3_EVENT_UPLOAD_PREFIX: events/
      LANGFUSE_S3_MEDIA_UPLOAD_BUCKET: langfuse
      LANGFUSE_S3_MEDIA_UPLOAD_REGION: auto
      LANGFUSE_S3_MEDIA_UPLOAD_ACCESS_KEY_ID: langfuse
      LANGFUSE_S3_MEDIA_UPLOAD_SECRET_ACCESS_KEY: "{{ secret.MINIO_PASSWORD }}"
      LANGFUSE_S3_MEDIA_UPLOAD_ENDPOINT: http://minio:9000
      LANGFUSE_S3_MEDIA_UPLOAD_FORCE_PATH_STYLE: "true"
      LANGFUSE_S3_MEDIA_UPLOAD_PREFIX: media/
      REDIS_HOST: redis
      REDIS_PORT: "6379"
      REDIS_AUTH: "{{ secret.REDIS_PASSWORD }}"
      LANGFUSE_INIT_ORG_ID: default-org
      LANGFUSE_INIT_ORG_NAME: Default organization
      LANGFUSE_INIT_PROJECT_ID: default-project
      LANGFUSE_INIT_PROJECT_NAME: Default project
      LANGFUSE_INIT_USER_EMAIL: "{{ input.admin_email }}"
      LANGFUSE_INIT_USER_NAME: Admin
      LANGFUSE_INIT_USER_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"

    depends_on:
      postgres:
        condition: service_healthy
      minio:
        condition: service_healthy
      redis:
        condition: service_healthy
      clickhouse:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "node", "-e", "fetch('http://127.0.0.1:3000/api/public/health').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 600s

  langfuse-worker:
    image: {{ image.worker }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["sh", "-c", "export ENCRYPTION_KEY=$$(printf %s $$ENCRYPTION_KEY_SEED | sha256sum | cut -c1-64) && unset ENCRYPTION_KEY_SEED && exec node worker/dist/index.js"]

    environment:
      NEXTAUTH_URL: "{{ url }}"
      DATABASE_URL: "postgresql://postgres:{{ secret.DB_PASSWORD }}@postgres:5432/postgres"
      SALT: "{{ secret.SALT }}"
      ENCRYPTION_KEY_SEED: "{{ secret.ENCRYPTION_KEY_SEED }}"
      CLICKHOUSE_MIGRATION_URL: clickhouse://clickhouse:9000
      CLICKHOUSE_URL: http://clickhouse:8123
      CLICKHOUSE_USER: clickhouse
      CLICKHOUSE_PASSWORD: "{{ secret.CLICKHOUSE_PASSWORD }}"
      CLICKHOUSE_CLUSTER_ENABLED: "false"
      LANGFUSE_S3_EVENT_UPLOAD_BUCKET: langfuse
      LANGFUSE_S3_EVENT_UPLOAD_REGION: auto
      LANGFUSE_S3_EVENT_UPLOAD_ACCESS_KEY_ID: langfuse
      LANGFUSE_S3_EVENT_UPLOAD_SECRET_ACCESS_KEY: "{{ secret.MINIO_PASSWORD }}"
      LANGFUSE_S3_EVENT_UPLOAD_ENDPOINT: http://minio:9000
      LANGFUSE_S3_EVENT_UPLOAD_FORCE_PATH_STYLE: "true"
      LANGFUSE_S3_EVENT_UPLOAD_PREFIX: events/
      LANGFUSE_S3_MEDIA_UPLOAD_BUCKET: langfuse
      LANGFUSE_S3_MEDIA_UPLOAD_REGION: auto
      LANGFUSE_S3_MEDIA_UPLOAD_ACCESS_KEY_ID: langfuse
      LANGFUSE_S3_MEDIA_UPLOAD_SECRET_ACCESS_KEY: "{{ secret.MINIO_PASSWORD }}"
      LANGFUSE_S3_MEDIA_UPLOAD_ENDPOINT: http://minio:9000
      LANGFUSE_S3_MEDIA_UPLOAD_FORCE_PATH_STYLE: "true"
      LANGFUSE_S3_MEDIA_UPLOAD_PREFIX: media/
      REDIS_HOST: redis
      REDIS_PORT: "6379"
      REDIS_AUTH: "{{ secret.REDIS_PASSWORD }}"

    depends_on:
      postgres:
        condition: service_healthy
      minio:
        condition: service_healthy
      redis:
        condition: service_healthy
      clickhouse:
        condition: service_healthy

  clickhouse:
    image: {{ image.clickhouse }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    user: "101:101"

    environment:
      CLICKHOUSE_DB: default
      CLICKHOUSE_USER: clickhouse
      CLICKHOUSE_PASSWORD: "{{ secret.CLICKHOUSE_PASSWORD }}"

    volumes:
      - {{ volume.clickhouse }}:/var/lib/clickhouse
      - {{ volume.clickhouse-logs }}:/var/log/clickhouse-server

    healthcheck:
      test: ["CMD-SHELL", "wget --no-verbose --tries=1 --spider http://localhost:8123/ping || exit 1"]
      interval: 5s
      timeout: 5s
      retries: 24
      start_period: 10s

  minio:
    image: {{ image.minio }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["sh", "-c", "mkdir -p /data/langfuse && exec silo server --address :9000 --console-address :9001 /data"]

    environment:
      MINIO_ROOT_USER: langfuse
      MINIO_ROOT_PASSWORD: "{{ secret.MINIO_PASSWORD }}"

    volumes:
      - {{ volume.minio }}:/data

    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://127.0.0.1:9000/minio/health/live"]
      interval: 5s
      timeout: 5s
      retries: 12

  redis:
    image: {{ image.redis }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["redis-server", "--requirepass", "{{ secret.REDIS_PASSWORD }}", "--maxmemory-policy", "noeviction"]

    volumes:
      - {{ volume.redis }}:/data

    healthcheck:
      test: ["CMD", "redis-cli", "-a", "{{ secret.REDIS_PASSWORD }}", "--no-auth-warning", "ping"]
      interval: 5s
      timeout: 5s
      retries: 12

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
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_DB: postgres
      TZ: UTC
      PGTZ: UTC

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U postgres"]
      interval: 5s
      timeout: 5s
      retries: 24
      start_period: 30s

volumes:
  {{ volume.clickhouse }}:
    external: true
  {{ volume.clickhouse-logs }}:
    external: true
  {{ volume.minio }}:
    external: true
  {{ volume.redis }}:
    external: true
  {{ volume.db }}:
    external: true
