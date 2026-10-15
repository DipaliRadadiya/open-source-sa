{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Invoice Ninja 5 (Debian image) with nginx, MySQL and Redis, from the official compose file and .env (RC-32). --}}
name: {{ project }}

services:
  nginx:
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

    volumes:
      - {{ site_root }}/etc/nginx/conf.d:/etc/nginx/conf.d:ro
      - {{ volume.public }}:/var/www/html/public:ro
      - {{ volume.storage }}:/var/www/html/storage:ro

    depends_on:
      app:
        condition: service_healthy

  app:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      APP_URL: "{{ url }}"
      APP_KEY: "base64:{{ secret.APP_KEY|base64 }}"
      APP_ENV: production
      APP_DEBUG: "false"
      REQUIRE_HTTPS: "false"
      PHANTOMJS_PDF_GENERATION: "false"
      PDF_GENERATOR: snappdf
      TRUSTED_PROXIES: "*"
      CACHE_DRIVER: redis
      QUEUE_CONNECTION: redis
      SESSION_DRIVER: redis
      REDIS_HOST: redis
      REDIS_PASSWORD: "null"
      REDIS_PORT: "6379"
      FILESYSTEM_DISK: debian_docker
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: ninja
      DB_USERNAME: ninja
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      IN_USER_EMAIL: "{{ input.admin_email }}"
      IN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"
      MAIL_MAILER: log
      IS_DOCKER: "true"
      SCOUT_DRIVER: "null"

    volumes:
      - {{ volume.public }}:/var/www/html/public
      - {{ volume.storage }}:/var/www/html/storage

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

    command: ["--performance-schema=OFF", "--innodb-log-buffer-size=16M"]

    environment:
      MYSQL_DATABASE: ninja
      MYSQL_USER: ninja
      MYSQL_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MYSQL_ROOT_PASSWORD: "{{ secret.DB_ROOT_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/mysql

    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "--silent"]
      interval: 5s
      timeout: 5s
      retries: 24
      start_period: 30s

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

volumes:
  {{ volume.public }}:
    external: true
  {{ volume.storage }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
