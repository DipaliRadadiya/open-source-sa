{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Snipe-IT with private MariaDB, from the official compose file and .env.docker (RC-32). --}}
name: {{ project }}

services:
  snipeit:
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
      APP_URL: "{{ url }}"
      APP_KEY: "base64:{{ secret.APP_KEY|base64 }}"
      APP_ENV: production
      APP_DEBUG: "false"
      APP_TIMEZONE: UTC
      APP_LOCALE: en-US
      APP_TRUSTED_PROXIES: "127.0.0.1,172.16.0.0/12,192.168.0.0/16,10.0.0.0/8"
      APP_TRUSTED_HEADERS: "HEADER_X_FORWARDED_FOR,HEADER_X_FORWARDED_HOST,HEADER_X_FORWARDED_PORT,HEADER_X_FORWARDED_PROTO"
      APP_FORCE_TLS: "false"
      SECURE_COOKIES: "true"
      PRIVATE_FILESYSTEM_DISK: local
      PUBLIC_FILESYSTEM_DISK: local_public
      DB_CONNECTION: mariadb
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: snipeit
      DB_USERNAME: snipeit
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      DB_CHARSET: utf8mb4
      DB_COLLATION: utf8mb4_unicode_ci
      MAIL_MAILER: log
      LOG_CHANNEL: stderr

    volumes:
      - {{ volume.data }}:/var/lib/snipeit

    depends_on:
      db:
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
      MYSQL_DATABASE: snipeit
      MYSQL_USER: snipeit
      MYSQL_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MYSQL_ROOT_PASSWORD: "{{ secret.DB_ROOT_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/mysql

    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 24

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
