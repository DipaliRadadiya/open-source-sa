{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Plane 1.4.2 community stack: Caddy proxy, web, space, admin, live, api, worker,
     beat worker, PostgreSQL, Valkey, RabbitMQ and Silo object storage (RC-33). --}}
name: {{ project }}

services:
  proxy:
    image: {{ image.proxy }}
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
      SITE_ADDRESS: ":80"
      FILE_SIZE_LIMIT: "5242880"
      BUCKET_NAME: uploads

    depends_on:
      - web
      - space
      - admin
      - live
      - api

  web:
    image: {{ image.web }}
    restart: unless-stopped
    mem_limit: 256m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    depends_on:
      - api
      - worker

  space:
    image: {{ image.space }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    depends_on:
      - api
      - worker
      - web

  admin:
    image: {{ image.admin }}
    restart: unless-stopped
    mem_limit: 256m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    depends_on:
      - api
      - web

  live:
    image: {{ image.live }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      API_BASE_URL: http://api:8000
      LIVE_SERVER_SECRET_KEY: "{{ secret.LIVE_SERVER_SECRET_KEY }}"
      REDIS_HOST: plane-redis
      REDIS_PORT: "6379"
      REDIS_URL: redis://plane-redis:6379/

    depends_on:
      - api
      - web

  api:
    image: {{ image.backend }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["sh", "-c", "./bin/docker-entrypoint-migrator.sh && exec ./bin/docker-entrypoint-api.sh"]

    environment:
      WEB_URL: "{{ url }}"
      CORS_ALLOWED_ORIGINS: "https://{{ domain }}"
      APP_DOMAIN: "{{ domain }}"
      DEBUG: "0"
      GUNICORN_WORKERS: "1"
      SECRET_KEY: "{{ secret.SECRET_KEY }}"
      LIVE_SERVER_SECRET_KEY: "{{ secret.LIVE_SERVER_SECRET_KEY }}"
      API_KEY_RATE_LIMIT: 60/minute
      DATABASE_URL: "postgresql://plane:{{ secret.DB_PASSWORD }}@plane-db:5432/plane"
      PGHOST: plane-db
      PGDATABASE: plane
      POSTGRES_USER: plane
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_DB: plane
      POSTGRES_PORT: "5432"
      REDIS_HOST: plane-redis
      REDIS_PORT: "6379"
      REDIS_URL: redis://plane-redis:6379/
      AMQP_URL: "amqp://plane:{{ secret.RABBITMQ_PASSWORD }}@plane-mq:5672/plane"
      USE_MINIO: "1"
      MINIO_ENDPOINT_SSL: "1"
      AWS_REGION: ""
      AWS_ACCESS_KEY_ID: plane
      AWS_SECRET_ACCESS_KEY: "{{ secret.MINIO_PASSWORD }}"
      AWS_S3_ENDPOINT_URL: http://plane-minio:9000
      AWS_S3_BUCKET_NAME: uploads
      FILE_SIZE_LIMIT: "5242880"

    volumes:
      - {{ volume.api-logs }}:/code/plane/logs

    depends_on:
      plane-db:
        condition: service_healthy
      plane-redis:
        condition: service_healthy
      plane-mq:
        condition: service_healthy
      plane-minio:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/api/instances/', timeout=5)"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 600s

  worker:
    image: {{ image.backend }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ./bin/docker-entrypoint-worker.sh

    environment:
      WEB_URL: "{{ url }}"
      CORS_ALLOWED_ORIGINS: "https://{{ domain }}"
      APP_DOMAIN: "{{ domain }}"
      DEBUG: "0"
      SECRET_KEY: "{{ secret.SECRET_KEY }}"
      LIVE_SERVER_SECRET_KEY: "{{ secret.LIVE_SERVER_SECRET_KEY }}"
      DATABASE_URL: "postgresql://plane:{{ secret.DB_PASSWORD }}@plane-db:5432/plane"
      PGHOST: plane-db
      PGDATABASE: plane
      POSTGRES_USER: plane
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_DB: plane
      POSTGRES_PORT: "5432"
      REDIS_HOST: plane-redis
      REDIS_PORT: "6379"
      REDIS_URL: redis://plane-redis:6379/
      AMQP_URL: "amqp://plane:{{ secret.RABBITMQ_PASSWORD }}@plane-mq:5672/plane"
      USE_MINIO: "1"
      MINIO_ENDPOINT_SSL: "1"
      AWS_REGION: ""
      AWS_ACCESS_KEY_ID: plane
      AWS_SECRET_ACCESS_KEY: "{{ secret.MINIO_PASSWORD }}"
      AWS_S3_ENDPOINT_URL: http://plane-minio:9000
      AWS_S3_BUCKET_NAME: uploads
      FILE_SIZE_LIMIT: "5242880"

    volumes:
      - {{ volume.worker-logs }}:/code/plane/logs

    depends_on:
      - api

  beat-worker:
    image: {{ image.backend }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ./bin/docker-entrypoint-beat.sh

    environment:
      WEB_URL: "{{ url }}"
      CORS_ALLOWED_ORIGINS: "https://{{ domain }}"
      APP_DOMAIN: "{{ domain }}"
      DEBUG: "0"
      SECRET_KEY: "{{ secret.SECRET_KEY }}"
      LIVE_SERVER_SECRET_KEY: "{{ secret.LIVE_SERVER_SECRET_KEY }}"
      DATABASE_URL: "postgresql://plane:{{ secret.DB_PASSWORD }}@plane-db:5432/plane"
      PGHOST: plane-db
      PGDATABASE: plane
      POSTGRES_USER: plane
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_DB: plane
      POSTGRES_PORT: "5432"
      REDIS_HOST: plane-redis
      REDIS_PORT: "6379"
      REDIS_URL: redis://plane-redis:6379/
      AMQP_URL: "amqp://plane:{{ secret.RABBITMQ_PASSWORD }}@plane-mq:5672/plane"
      USE_MINIO: "1"
      MINIO_ENDPOINT_SSL: "1"
      AWS_REGION: ""
      AWS_ACCESS_KEY_ID: plane
      AWS_SECRET_ACCESS_KEY: "{{ secret.MINIO_PASSWORD }}"
      AWS_S3_ENDPOINT_URL: http://plane-minio:9000
      AWS_S3_BUCKET_NAME: uploads
      FILE_SIZE_LIMIT: "5242880"

    volumes:
      - {{ volume.beat-logs }}:/code/plane/logs

    depends_on:
      - api

  plane-db:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["postgres", "-c", "max_connections=1000"]

    environment:
      POSTGRES_USER: plane
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_DB: plane
      PGDATA: /var/lib/postgresql/data

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "plane", "-d", "plane"]
      interval: 5s
      timeout: 5s
      retries: 24
      start_period: 30s

  plane-redis:
    image: {{ image.redis }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    volumes:
      - {{ volume.redis }}:/data

    healthcheck:
      test: ["CMD", "valkey-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 12

  plane-mq:
    image: {{ image.mq }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    hostname: plane-mq

    environment:
      RABBITMQ_DEFAULT_USER: plane
      RABBITMQ_DEFAULT_PASS: "{{ secret.RABBITMQ_PASSWORD }}"
      RABBITMQ_DEFAULT_VHOST: plane

    volumes:
      - {{ volume.mq }}:/var/lib/rabbitmq

    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "-q", "ping"]
      interval: 10s
      timeout: 10s
      retries: 12
      start_period: 30s

  plane-minio:
    image: {{ image.minio }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["server", "/data", "--console-address", ":9090"]

    environment:
      MINIO_ROOT_USER: plane
      MINIO_ROOT_PASSWORD: "{{ secret.MINIO_PASSWORD }}"

    volumes:
      - {{ volume.uploads }}:/data

    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://127.0.0.1:9000/minio/health/live"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.api-logs }}:
    external: true
  {{ volume.worker-logs }}:
    external: true
  {{ volume.beat-logs }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
  {{ volume.mq }}:
    external: true
  {{ volume.uploads }}:
    external: true
