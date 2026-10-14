{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Supabase self-hosted (docker/ at tag self-hosted/v0.8.2): Kong (the published
     gateway, Studio behind basic auth plus every API), Studio, Auth, PostgREST,
     Realtime, Storage, imgproxy, postgres-meta, Edge Functions, PostgreSQL (RC-41). --}}
name: {{ project }}

services:
  kong:
    image: {{ image.kong }}
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

    entrypoint: ["/bin/sh", "-c", ". /opt/supabase-keys/jwt-keys.sh && exec /bin/sh /home/kong/kong-entrypoint.sh"]

    environment:
      KONG_DATABASE: "off"
      KONG_DECLARATIVE_CONFIG: /usr/local/kong/kong.yml
      KONG_ROUTER_FLAVOR: expressions
      KONG_DNS_ORDER: LAST,A,CNAME
      KONG_DNS_NOT_FOUND_TTL: "1"
      KONG_DNS_VALID_TTL: "5"
      KONG_PLUGINS: request-transformer,cors,key-auth,acl,basic-auth,request-termination,ip-restriction,post-function
      KONG_NGINX_PROXY_PROXY_BUFFER_SIZE: 160k
      KONG_NGINX_PROXY_PROXY_BUFFERS: 64 160k
      KONG_PROXY_ACCESS_LOG: /dev/stdout combined
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      SUPABASE_PUBLISHABLE_KEY: ""
      SUPABASE_SECRET_KEY: ""
      ANON_KEY_ASYMMETRIC: ""
      SERVICE_ROLE_KEY_ASYMMETRIC: ""
      DASHBOARD_USERNAME: supabase
      DASHBOARD_PASSWORD: "{{ secret.DASHBOARD_PASSWORD }}"

    volumes:
      - {{ site_root }}/home/kong/temp.yml:/home/kong/temp.yml:ro
      - {{ site_root }}/home/kong/kong-entrypoint.sh:/home/kong/kong-entrypoint.sh:ro
      - {{ site_root }}/opt/supabase-keys:/opt/supabase-keys:ro

    depends_on:
      studio:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "kong", "health"]
      interval: 5s
      timeout: 5s
      retries: 5

  studio:
    image: {{ image.studio }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    entrypoint: ["/bin/sh", "-c", ". /opt/supabase-keys/jwt-keys.sh && exec docker-entrypoint.sh node apps/studio/server.js"]

    environment:
      HOSTNAME: "0.0.0.0"
      STUDIO_PG_META_URL: http://meta:8080
      POSTGRES_PORT: "5432"
      POSTGRES_HOST: db
      POSTGRES_DB: postgres
      POSTGRES_PASSWORD: "{{ secret.POSTGRES_PASSWORD }}"
      POSTGRES_USER_READ_WRITE: postgres
      PG_META_CRYPTO_KEY: "{{ secret.PG_META_CRYPTO_KEY }}"
      PGRST_DB_SCHEMAS: public,graphql_public
      PGRST_DB_MAX_ROWS: "1000"
      PGRST_DB_EXTRA_SEARCH_PATH: public
      DEFAULT_ORGANIZATION_NAME: Default Organization
      DEFAULT_PROJECT_NAME: Default Project
      OPENAI_API_KEY: ""
      SUPABASE_URL: http://kong:8000
      SUPABASE_PUBLIC_URL: "{{ url }}"
      AUTH_JWT_SECRET: "{{ secret.JWT_SECRET }}"
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      SUPABASE_PUBLISHABLE_KEY: ""
      SUPABASE_SECRET_KEY: ""
      ENABLED_FEATURES_LOGS_ALL: "false"
      SNIPPETS_MANAGEMENT_FOLDER: /app/snippets
      EDGE_FUNCTIONS_MANAGEMENT_FOLDER: /app/edge-functions

    volumes:
      - {{ volume.snippets }}:/app/snippets
      - {{ site_root }}/home/deno/functions:/app/edge-functions:ro
      - {{ site_root }}/opt/supabase-keys:/opt/supabase-keys:ro

    healthcheck:
      test: ["CMD-SHELL", "node -e \"fetch('http://localhost:3000/api/platform/profile').then((r) => {if (r.status !== 200) throw new Error(r.status)})\""]
      interval: 5s
      timeout: 10s
      retries: 3
      start_period: 20s

  auth:
    image: {{ image.auth }}
    restart: unless-stopped
    mem_limit: 256m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      GOTRUE_API_HOST: 0.0.0.0
      GOTRUE_API_PORT: "9999"
      API_EXTERNAL_URL: "https://{{ domain }}/auth/v1"
      GOTRUE_DB_DRIVER: postgres
      GOTRUE_DB_DATABASE_URL: "postgres://supabase_auth_admin:{{ secret.POSTGRES_PASSWORD }}@db:5432/postgres"
      GOTRUE_SITE_URL: "https://{{ domain }}"
      GOTRUE_URI_ALLOW_LIST: ""
      GOTRUE_DISABLE_SIGNUP: "false"
      GOTRUE_JWT_ADMIN_ROLES: service_role
      GOTRUE_JWT_AUD: authenticated
      GOTRUE_JWT_DEFAULT_GROUP_NAME: authenticated
      GOTRUE_JWT_EXP: "3600"
      GOTRUE_JWT_SECRET: "{{ secret.JWT_SECRET }}"
      GOTRUE_JWT_ISSUER: "https://{{ domain }}/auth/v1"
      GOTRUE_EXTERNAL_EMAIL_ENABLED: "true"
      GOTRUE_EXTERNAL_ANONYMOUS_USERS_ENABLED: "false"
      GOTRUE_MAILER_AUTOCONFIRM: "true"
      GOTRUE_MAILER_URLPATHS_INVITE: /auth/v1/verify
      GOTRUE_MAILER_URLPATHS_CONFIRMATION: /auth/v1/verify
      GOTRUE_MAILER_URLPATHS_RECOVERY: /auth/v1/verify
      GOTRUE_MAILER_URLPATHS_EMAIL_CHANGE: /auth/v1/verify
      GOTRUE_EXTERNAL_PHONE_ENABLED: "false"
      GOTRUE_SMS_AUTOCONFIRM: "false"

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost:9999/health"]
      interval: 5s
      timeout: 5s
      retries: 3

  rest:
    image: {{ image.rest }}
    restart: unless-stopped
    mem_limit: 256m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["postgrest"]

    environment:
      PGRST_DB_URI: "postgres://authenticator:{{ secret.POSTGRES_PASSWORD }}@db:5432/postgres"
      PGRST_DB_SCHEMAS: public,graphql_public
      PGRST_DB_MAX_ROWS: "1000"
      PGRST_DB_EXTRA_SEARCH_PATH: public
      PGRST_DB_ANON_ROLE: anon
      PGRST_ADMIN_SERVER_PORT: "3001"
      PGRST_ADMIN_SERVER_HOST: localhost
      PGRST_JWT_SECRET: "{{ secret.JWT_SECRET }}"
      PGRST_DB_USE_LEGACY_GUCS: "false"
      PGRST_APP_SETTINGS_JWT_EXP: "3600"

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "postgrest", "--ready"]
      interval: 5s
      timeout: 5s
      retries: 3

  realtime:
    image: {{ image.realtime }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    entrypoint: ["/bin/sh", "-c", "export DB_ENC_KEY=$$(printf %s \"$$REALTIME_ENC_KEY_SEED\" | cut -c1-16) && exec /usr/bin/tini -s -g -- /app/run.sh /app/bin/server"]

    environment:
      PORT: "4000"
      DB_HOST: db
      DB_PORT: "5432"
      DB_USER: supabase_admin
      DB_PASSWORD: "{{ secret.POSTGRES_PASSWORD }}"
      DB_NAME: postgres
      DB_AFTER_CONNECT_QUERY: SET search_path TO _realtime
      REALTIME_ENC_KEY_SEED: "{{ secret.REALTIME_DB_ENC_KEY }}"
      API_JWT_SECRET: "{{ secret.JWT_SECRET }}"
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      SECRET_KEY_BASE: "{{ secret.SECRET_KEY_BASE }}{{ secret.SECRET_KEY_BASE_2 }}"
      METRICS_JWT_SECRET: "{{ secret.JWT_SECRET }}"
      ERL_AFLAGS: -proto_dist inet_tcp
      DNS_NODES: "''"
      RLIMIT_NOFILE: "10000"
      APP_NAME: realtime
      SEED_SELF_HOST: "true"
      RUN_JANITOR: "true"
      DISABLE_HEALTHCHECK_LOGGING: "true"

    volumes:
      - {{ site_root }}/opt/supabase-keys:/opt/supabase-keys:ro

    networks:
      default:
        aliases:
          - realtime-dev.supabase-realtime

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", ". /opt/supabase-keys/jwt-keys.sh && curl -sSfL --head -o /dev/null -H \"Authorization: Bearer $$ANON_KEY\" http://localhost:4000/api/tenants/realtime-dev/health"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s

  storage:
    image: {{ image.storage }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    entrypoint: ["/bin/sh", "-c", ". /opt/supabase-keys/jwt-keys.sh && exec docker-entrypoint.sh node dist/start/server.js"]

    environment:
      POSTGREST_URL: http://rest:3000
      AUTH_JWT_SECRET: "{{ secret.JWT_SECRET }}"
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      DATABASE_URL: "postgres://supabase_storage_admin:{{ secret.POSTGRES_PASSWORD }}@db:5432/postgres"
      STORAGE_PUBLIC_URL: "https://{{ domain }}"
      REQUEST_ALLOW_X_FORWARDED_PATH: "true"
      FILE_SIZE_LIMIT: "52428800"
      STORAGE_BACKEND: file
      GLOBAL_S3_BUCKET: stub
      FILE_STORAGE_BACKEND_PATH: /var/lib/storage
      TENANT_ID: stub
      REGION: stub
      ENABLE_IMAGE_TRANSFORMATION: "true"
      IMGPROXY_URL: http://imgproxy:5001
      S3_PROTOCOL_ACCESS_KEY_ID: "{{ secret.S3_PROTOCOL_ACCESS_KEY_ID }}"
      S3_PROTOCOL_ACCESS_KEY_SECRET: "{{ secret.S3_PROTOCOL_ACCESS_KEY_SECRET }}"

    volumes:
      - {{ volume.storage }}:/var/lib/storage
      - {{ site_root }}/opt/supabase-keys:/opt/supabase-keys:ro

    depends_on:
      db:
        condition: service_healthy
      rest:
        condition: service_started
      imgproxy:
        condition: service_started

    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://storage:5000/status"]
      interval: 5s
      timeout: 5s
      retries: 3
      start_period: 10s

  imgproxy:
    image: {{ image.imgproxy }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      IMGPROXY_BIND: ":5001"
      IMGPROXY_LOCAL_FILESYSTEM_ROOT: /
      IMGPROXY_USE_ETAG: "true"
      IMGPROXY_AUTO_WEBP: "true"
      IMGPROXY_MAX_SRC_RESOLUTION: "16.8"

    volumes:
      - {{ volume.storage }}:/var/lib/storage

    healthcheck:
      test: ["CMD", "imgproxy", "health"]
      interval: 5s
      timeout: 5s
      retries: 3

  meta:
    image: {{ image.meta }}
    restart: unless-stopped
    mem_limit: 256m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      PG_META_PORT: "8080"
      PG_META_DB_HOST: db
      PG_META_DB_PORT: "5432"
      PG_META_DB_NAME: postgres
      PG_META_DB_USER: postgres
      PG_META_DB_PASSWORD: "{{ secret.POSTGRES_PASSWORD }}"
      CRYPTO_KEY: "{{ secret.PG_META_CRYPTO_KEY }}"

    depends_on:
      db:
        condition: service_healthy

  functions:
    image: {{ image.functions }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["start", "--main-service", "/home/deno/functions/main"]

    environment:
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      SUPABASE_URL: http://kong:8000
      SUPABASE_PUBLIC_URL: "{{ url }}"
      SUPABASE_DB_URL: "postgresql://postgres:{{ secret.POSTGRES_PASSWORD }}@db:5432/postgres"
      VERIFY_JWT: "false"

    volumes:
      - {{ site_root }}/home/deno/functions:/home/deno/functions
      - {{ volume.deno-cache }}:/root/.cache/deno

    depends_on:
      kong:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", "timeout 1 bash -c '</dev/tcp/127.0.0.1/9000'"]
      interval: 5s
      timeout: 5s
      retries: 3

  db:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["postgres", "-c", "config_file=/etc/postgresql/postgresql.conf", "-c", "log_min_messages=fatal"]

    environment:
      POSTGRES_HOST: /var/run/postgresql
      PGPORT: "5432"
      POSTGRES_PORT: "5432"
      PGPASSWORD: "{{ secret.POSTGRES_PASSWORD }}"
      POSTGRES_PASSWORD: "{{ secret.POSTGRES_PASSWORD }}"
      PGDATABASE: postgres
      POSTGRES_DB: postgres
      JWT_EXP: "3600"

    volumes:
      - {{ site_root }}/docker-entrypoint-initdb.d/migrations/99-realtime.sql:/docker-entrypoint-initdb.d/migrations/99-realtime.sql:ro
      - {{ site_root }}/docker-entrypoint-initdb.d/init-scripts/98-webhooks.sql:/docker-entrypoint-initdb.d/init-scripts/98-webhooks.sql:ro
      - {{ site_root }}/docker-entrypoint-initdb.d/init-scripts/99-roles.sql:/docker-entrypoint-initdb.d/init-scripts/99-roles.sql:ro
      - {{ site_root }}/docker-entrypoint-initdb.d/init-scripts/99-jwt.sql:/docker-entrypoint-initdb.d/init-scripts/99-jwt.sql:ro
      - {{ site_root }}/docker-entrypoint-initdb.d/migrations/97-_supabase.sql:/docker-entrypoint-initdb.d/migrations/97-_supabase.sql:ro
      - {{ site_root }}/docker-entrypoint-initdb.d/migrations/99-logs.sql:/docker-entrypoint-initdb.d/migrations/99-logs.sql:ro
      - {{ site_root }}/docker-entrypoint-initdb.d/migrations/99-pooler.sql:/docker-entrypoint-initdb.d/migrations/99-pooler.sql:ro
      - {{ volume.db }}:/var/lib/postgresql/data
      - {{ volume.db-config }}:/etc/postgresql-custom

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "postgres", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10

volumes:
  {{ volume.snippets }}:
    external: true
  {{ volume.storage }}:
    external: true
  {{ volume.deno-cache }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.db-config }}:
    external: true
