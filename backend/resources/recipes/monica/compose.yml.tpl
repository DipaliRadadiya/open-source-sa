{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Monica 4 (Apache image) with its scheduler and private MariaDB, from the official Docker examples (RC-36). --}}
name: {{ project }}

services:
  monica:
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
      HASH_SALT: "{{ secret.HASH_SALT }}"
      APP_ENV: production
      APP_DEBUG: "false"
      APP_TRUSTED_PROXIES: "*"
      APP_DISABLE_SIGNUP: "true"
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: monica
      DB_USERNAME: monica
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MAIL_MAILER: log
      LOG_CHANNEL: stderr

    volumes:
      - {{ volume.storage }}:/var/www/html/storage

    depends_on:
      db:
        condition: service_healthy

  cron:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: 256m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["cron.sh"]

    environment:
      APP_URL: "{{ url }}"
      APP_KEY: "base64:{{ secret.APP_KEY|base64 }}"
      HASH_SALT: "{{ secret.HASH_SALT }}"
      APP_ENV: production
      APP_DEBUG: "false"
      APP_DISABLE_SIGNUP: "true"
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: monica
      DB_USERNAME: monica
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MAIL_MAILER: log
      LOG_CHANNEL: stderr

    volumes:
      - {{ volume.storage }}:/var/www/html/storage

    depends_on:
      monica:
        condition: service_started

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
      MYSQL_DATABASE: monica
      MYSQL_USER: monica
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
  {{ volume.storage }}:
    external: true
  {{ volume.db }}:
    external: true
