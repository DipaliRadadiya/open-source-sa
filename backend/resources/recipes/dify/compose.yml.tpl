{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Dify 1.17.1 core stack: nginx, api, worker, beat, web, sandbox, plugin daemon,
     SSRF proxy, PostgreSQL with pgvector, Redis (RC-19). --}}
name: {{ project }}

services:
  nginx:
    image: {{ image.nginx }}
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

    volumes:
      - {{ site_root }}/etc/nginx/conf.d:/etc/nginx/conf.d:ro

    depends_on:
      - api
      - web

  api:
    image: {{ image.api }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    user: root
    entrypoint: ["/bin/bash", "-c", "chown 1001:1001 /app/api/storage && exec setpriv --reuid=1001 --regid=1001 --init-groups /bin/bash /entrypoint.sh"]

    environment:
      MODE: api
      SECRET_KEY: "{{ secret.SECRET_KEY }}"
      DEPLOY_ENV: PRODUCTION
      LOG_LEVEL: INFO
      MIGRATION_ENABLED: "true"
      CONSOLE_API_URL: ""
      CONSOLE_WEB_URL: ""
      SERVICE_API_URL: ""
      APP_WEB_URL: ""
      FILES_URL: "{{ url }}"
      INTERNAL_FILES_URL: http://api:5001
      TRIGGER_URL: "https://{{ domain }}"
      ENDPOINT_URL_TEMPLATE: "https://{{ domain }}/e/{hook_id}"
      RESPECT_XFORWARD_HEADERS_ENABLED: "true"
      ENABLE_COLLABORATION_MODE: "false"
      WEB_API_CORS_ALLOW_ORIGINS: "*"
      CONSOLE_CORS_ALLOW_ORIGINS: "*"
      DB_TYPE: postgresql
      DB_USERNAME: postgres
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      DB_HOST: db
      DB_PORT: "5432"
      DB_DATABASE: dify
      REDIS_HOST: redis
      REDIS_PORT: "6379"
      REDIS_PASSWORD: "{{ secret.REDIS_PASSWORD }}"
      REDIS_DB: "0"
      CELERY_BROKER_URL: "redis://:{{ secret.REDIS_PASSWORD }}@redis:6379/1"
      STORAGE_TYPE: opendal
      OPENDAL_SCHEME: fs
      OPENDAL_FS_ROOT: storage
      VECTOR_STORE: pgvector
      PGVECTOR_HOST: db
      PGVECTOR_PORT: "5432"
      PGVECTOR_USER: postgres
      PGVECTOR_PASSWORD: "{{ secret.DB_PASSWORD }}"
      PGVECTOR_DATABASE: dify
      CODE_EXECUTION_ENDPOINT: http://sandbox:8194
      CODE_EXECUTION_API_KEY: "{{ secret.SANDBOX_API_KEY }}"
      SSRF_PROXY_HTTP_URL: http://ssrf_proxy:3128
      SSRF_PROXY_HTTPS_URL: http://ssrf_proxy:3128
      PLUGIN_DAEMON_URL: http://plugin_daemon:5002
      PLUGIN_DAEMON_KEY: "{{ secret.PLUGIN_DAEMON_KEY }}"
      INNER_API_KEY_FOR_PLUGIN: "{{ secret.PLUGIN_DIFY_INNER_API_KEY }}"
      PLUGIN_MAX_PACKAGE_SIZE: "52428800"
      MARKETPLACE_ENABLED: "true"
      MARKETPLACE_API_URL: https://marketplace.dify.ai

    volumes:
      - {{ volume.storage }}:/app/api/storage

    networks:
      - ssrf_proxy_network
      - default

    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started

    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:5001/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 300s

  worker:
    image: {{ image.api }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      MODE: worker
      SECRET_KEY: "{{ secret.SECRET_KEY }}"
      DEPLOY_ENV: PRODUCTION
      LOG_LEVEL: INFO
      MIGRATION_ENABLED: "true"
      CONSOLE_API_URL: ""
      CONSOLE_WEB_URL: ""
      SERVICE_API_URL: ""
      APP_WEB_URL: ""
      FILES_URL: "{{ url }}"
      INTERNAL_FILES_URL: http://api:5001
      TRIGGER_URL: "https://{{ domain }}"
      ENDPOINT_URL_TEMPLATE: "https://{{ domain }}/e/{hook_id}"
      CELERY_WORKER_AMOUNT: "4"
      ENABLE_COLLABORATION_MODE: "false"
      WEB_API_CORS_ALLOW_ORIGINS: "*"
      CONSOLE_CORS_ALLOW_ORIGINS: "*"
      DB_TYPE: postgresql
      DB_USERNAME: postgres
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      DB_HOST: db
      DB_PORT: "5432"
      DB_DATABASE: dify
      REDIS_HOST: redis
      REDIS_PORT: "6379"
      REDIS_PASSWORD: "{{ secret.REDIS_PASSWORD }}"
      REDIS_DB: "0"
      CELERY_BROKER_URL: "redis://:{{ secret.REDIS_PASSWORD }}@redis:6379/1"
      STORAGE_TYPE: opendal
      OPENDAL_SCHEME: fs
      OPENDAL_FS_ROOT: storage
      VECTOR_STORE: pgvector
      PGVECTOR_HOST: db
      PGVECTOR_PORT: "5432"
      PGVECTOR_USER: postgres
      PGVECTOR_PASSWORD: "{{ secret.DB_PASSWORD }}"
      PGVECTOR_DATABASE: dify
      CODE_EXECUTION_ENDPOINT: http://sandbox:8194
      CODE_EXECUTION_API_KEY: "{{ secret.SANDBOX_API_KEY }}"
      SSRF_PROXY_HTTP_URL: http://ssrf_proxy:3128
      SSRF_PROXY_HTTPS_URL: http://ssrf_proxy:3128
      PLUGIN_DAEMON_URL: http://plugin_daemon:5002
      PLUGIN_DAEMON_KEY: "{{ secret.PLUGIN_DAEMON_KEY }}"
      INNER_API_KEY_FOR_PLUGIN: "{{ secret.PLUGIN_DIFY_INNER_API_KEY }}"
      PLUGIN_MAX_PACKAGE_SIZE: "52428800"
      MARKETPLACE_ENABLED: "true"
      MARKETPLACE_API_URL: https://marketplace.dify.ai

    volumes:
      - {{ volume.storage }}:/app/api/storage

    networks:
      - ssrf_proxy_network
      - default

    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started
      api:
        condition: service_started

  worker_beat:
    image: {{ image.api }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      MODE: beat
      SECRET_KEY: "{{ secret.SECRET_KEY }}"
      DEPLOY_ENV: PRODUCTION
      LOG_LEVEL: INFO
      MIGRATION_ENABLED: "true"
      CONSOLE_API_URL: ""
      CONSOLE_WEB_URL: ""
      SERVICE_API_URL: ""
      APP_WEB_URL: ""
      FILES_URL: "{{ url }}"
      INTERNAL_FILES_URL: http://api:5001
      TRIGGER_URL: "https://{{ domain }}"
      ENDPOINT_URL_TEMPLATE: "https://{{ domain }}/e/{hook_id}"
      ENABLE_COLLABORATION_MODE: "false"
      WEB_API_CORS_ALLOW_ORIGINS: "*"
      CONSOLE_CORS_ALLOW_ORIGINS: "*"
      DB_TYPE: postgresql
      DB_USERNAME: postgres
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      DB_HOST: db
      DB_PORT: "5432"
      DB_DATABASE: dify
      REDIS_HOST: redis
      REDIS_PORT: "6379"
      REDIS_PASSWORD: "{{ secret.REDIS_PASSWORD }}"
      REDIS_DB: "0"
      CELERY_BROKER_URL: "redis://:{{ secret.REDIS_PASSWORD }}@redis:6379/1"
      STORAGE_TYPE: opendal
      OPENDAL_SCHEME: fs
      OPENDAL_FS_ROOT: storage
      VECTOR_STORE: pgvector
      PGVECTOR_HOST: db
      PGVECTOR_PORT: "5432"
      PGVECTOR_USER: postgres
      PGVECTOR_PASSWORD: "{{ secret.DB_PASSWORD }}"
      PGVECTOR_DATABASE: dify
      CODE_EXECUTION_ENDPOINT: http://sandbox:8194
      CODE_EXECUTION_API_KEY: "{{ secret.SANDBOX_API_KEY }}"
      SSRF_PROXY_HTTP_URL: http://ssrf_proxy:3128
      SSRF_PROXY_HTTPS_URL: http://ssrf_proxy:3128
      PLUGIN_DAEMON_URL: http://plugin_daemon:5002
      PLUGIN_DAEMON_KEY: "{{ secret.PLUGIN_DAEMON_KEY }}"
      INNER_API_KEY_FOR_PLUGIN: "{{ secret.PLUGIN_DIFY_INNER_API_KEY }}"
      PLUGIN_MAX_PACKAGE_SIZE: "52428800"
      MARKETPLACE_ENABLED: "true"
      MARKETPLACE_API_URL: https://marketplace.dify.ai

    networks:
      - ssrf_proxy_network
      - default

    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started

  web:
    image: {{ image.web }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      CONSOLE_API_URL: ""
      APP_API_URL: ""
      SERVER_CONSOLE_API_URL: http://api:5001
      MARKETPLACE_API_URL: https://marketplace.dify.ai
      MARKETPLACE_URL: https://marketplace.dify.ai
      TEXT_GENERATION_TIMEOUT_MS: "60000"
      CSP_WHITELIST: ""
      ALLOW_EMBED: "false"
      TOP_K_MAX_VALUE: "10"
      INDEXING_MAX_SEGMENTATION_TOKENS_LENGTH: "4000"
      LOOP_NODE_MAX_COUNT: "100"
      MAX_TOOLS_NUM: "10"
      MAX_PARALLEL_LIMIT: "10"
      MAX_ITERATIONS_NUM: "99"
      MAX_TREE_DEPTH: "50"

  sandbox:
    image: {{ image.sandbox }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      API_KEY: "{{ secret.SANDBOX_API_KEY }}"
      GIN_MODE: release
      WORKER_TIMEOUT: "15"
      ENABLE_NETWORK: "true"
      HTTP_PROXY: http://ssrf_proxy:3128
      HTTPS_PROXY: http://ssrf_proxy:3128
      SANDBOX_PORT: "8194"

    volumes:
      - {{ volume.sandbox-deps }}:/dependencies
      - {{ site_root }}/conf:/conf

    networks:
      - ssrf_proxy_network

    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8194/health"]
      interval: 30s
      timeout: 5s
      retries: 3

  plugin_daemon:
    image: {{ image.plugin_daemon }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      DB_TYPE: postgresql
      DB_HOST: db
      DB_PORT: "5432"
      DB_USERNAME: postgres
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      DB_DATABASE: dify_plugin
      DB_SSL_MODE: disable
      REDIS_HOST: redis
      REDIS_PORT: "6379"
      REDIS_PASSWORD: "{{ secret.REDIS_PASSWORD }}"
      SERVER_PORT: "5002"
      SERVER_KEY: "{{ secret.PLUGIN_DAEMON_KEY }}"
      MAX_PLUGIN_PACKAGE_SIZE: "52428800"
      PPROF_ENABLED: "false"
      DIFY_INNER_API_URL: http://api:5001
      DIFY_INNER_API_KEY: "{{ secret.PLUGIN_DIFY_INNER_API_KEY }}"
      PLUGIN_REMOTE_INSTALLING_HOST: 0.0.0.0
      PLUGIN_REMOTE_INSTALLING_PORT: "5003"
      PLUGIN_WORKING_PATH: /app/storage/cwd
      FORCE_VERIFYING_SIGNATURE: "true"
      PYTHON_ENV_INIT_TIMEOUT: "120"
      PLUGIN_MAX_EXECUTION_TIMEOUT: "600"
      PLUGIN_STORAGE_TYPE: local
      PLUGIN_STORAGE_LOCAL_ROOT: /app/storage
      PLUGIN_INSTALLED_PATH: plugin
      PLUGIN_PACKAGE_CACHE_PATH: plugin_packages
      PLUGIN_MEDIA_CACHE_PATH: assets

    volumes:
      - {{ volume.plugins }}:/app/storage

    networks:
      - ssrf_proxy_network
      - default

    depends_on:
      db:
        condition: service_healthy

  ssrf_proxy:
    image: {{ image.squid }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["-f", "/etc/squid/dify/squid.conf", "-NYC"]

    volumes:
      - {{ site_root }}/etc/squid/dify:/etc/squid/dify:ro

    networks:
      - ssrf_proxy_network
      - default

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
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_DB: dify
      PGDATA: /var/lib/postgresql/data/pgdata

    command: ["postgres", "-c", "max_connections=200", "-c", "shared_buffers=128MB", "-c", "work_mem=4MB", "-c", "maintenance_work_mem=64MB", "-c", "effective_cache_size=4096MB"]

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-h", "localhost", "-U", "postgres", "-d", "dify"]
      interval: 2s
      timeout: 3s
      retries: 60

  redis:
    image: {{ image.redis }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      REDISCLI_AUTH: "{{ secret.REDIS_PASSWORD }}"

    command: ["redis-server", "--requirepass", "{{ secret.REDIS_PASSWORD }}"]

    volumes:
      - {{ volume.redis }}:/data

    healthcheck:
      test: ["CMD-SHELL", "redis-cli ping | grep -q PONG"]
      interval: 5s
      timeout: 3s
      retries: 10

networks:
  ssrf_proxy_network:
    driver: bridge
    internal: true

volumes:
  {{ volume.storage }}:
    external: true
  {{ volume.plugins }}:
    external: true
  {{ volume.sandbox-deps }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
