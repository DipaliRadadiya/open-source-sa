{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     AFFiNE with folded migrations, private pgvector PostgreSQL and Redis (RC-18). --}}
name: {{ project }}

services:
  affine:
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
      REDIS_SERVER_HOST: redis
      DATABASE_URL: "postgresql://affine:{{ secret.DB_PASSWORD }}@postgres:5432/affine"
      AFFINE_INDEXER_ENABLED: "false"
      AFFINE_SERVER_EXTERNAL_URL: "{{ url }}"

    command: ["sh", "-c", "node ./scripts/self-host-predeploy.js && exec node ./dist/main.js"]

    volumes:
      - {{ volume.storage }}:/root/.affine/storage
      - {{ volume.config }}:/root/.affine/config

    depends_on:
      redis:
        condition: service_healthy
      postgres:
        condition: service_healthy

  redis:
    image: {{ image.redis }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    healthcheck:
      test: ["CMD", "redis-cli", "--raw", "incr", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

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
      POSTGRES_USER: affine
      POSTGRES_DB: affine
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_INITDB_ARGS: "--data-checksums"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "affine", "-d", "affine"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  {{ volume.storage }}:
    external: true
  {{ volume.config }}:
    external: true
  {{ volume.db }}:
    external: true
