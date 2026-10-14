{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Postiz with PostgreSQL, Redis and a Temporal server on PostgreSQL visibility (RC-40). --}}
name: {{ project }}

services:
  postiz:
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
      FRONTEND_URL: "{{ url }}"
      MAIN_URL: "https://{{ domain }}"
      NEXT_PUBLIC_BACKEND_URL: "https://{{ domain }}/api"
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      DATABASE_URL: "postgresql://postiz:{{ secret.DB_PASSWORD }}@db:5432/postiz"
      REDIS_URL: redis://redis:6379
      BACKEND_INTERNAL_URL: http://localhost:3000
      TEMPORAL_ADDRESS: temporal:7233
      IS_GENERAL: "true"
      DISABLE_REGISTRATION: "true"
      RUN_CRON: "true"
      STORAGE_PROVIDER: local
      UPLOAD_DIRECTORY: /uploads
      NEXT_PUBLIC_UPLOAD_DIRECTORY: /uploads
      API_LIMIT: "30"

    volumes:
      - {{ volume.config }}:/config
      - {{ volume.uploads }}:/uploads

    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
      temporal:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "node", "-e", "const r=require('http').get('http://127.0.0.1:5000/',res=>process.exit(res.statusCode<500?0:1));r.on('error',()=>process.exit(1));r.setTimeout(4000,()=>{r.destroy();process.exit(1)})"]
      interval: 15s
      timeout: 10s
      retries: 10
      start_period: 300s

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
      POSTGRES_DB: postiz
      POSTGRES_USER: postiz
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "postiz", "-d", "postiz"]
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

    volumes:
      - {{ volume.redis }}:/data

    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 12

  temporal:
    image: {{ image.temporal }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      DB: postgres12
      DB_PORT: "5432"
      POSTGRES_USER: postiz
      POSTGRES_PWD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_SEEDS: db
      DYNAMIC_CONFIG_FILE_PATH: config/dynamicconfig/development-sql.yaml
      SKIP_ADD_CUSTOM_SEARCH_ATTRIBUTES: "true"

    volumes:
      - {{ site_root }}/etc/temporal/config/dynamicconfig:/etc/temporal/config/dynamicconfig:ro

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "temporal", "operator", "cluster", "health", "--address", "temporal:7233"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 60s

volumes:
  {{ volume.config }}:
    external: true
  {{ volume.uploads }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
