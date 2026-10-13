{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Firefly III with private MariaDB and the daily cron call, from the official compose file and .env.example (RC-36). --}}
name: {{ project }}

services:
  app:
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
      APP_KEY: "{{ secret.APP_KEY }}"
      APP_ENV: production
      APP_DEBUG: "false"
      TZ: UTC
      TRUSTED_PROXIES: "**"
      LOG_CHANNEL: stdout
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: firefly
      DB_USERNAME: firefly
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MAIL_MAILER: log
      STATIC_CRON_TOKEN: "{{ secret.STATIC_CRON_TOKEN }}"

    volumes:
      - {{ volume.upload }}:/var/www/html/storage/upload

    depends_on:
      db:
        condition: service_healthy

  cron:
    image: {{ image.cron }}
    restart: unless-stopped
    mem_limit: 32m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command:
      - sh
      - -c
      - 'while :; do sleep 3600; { printf "GET /api/v1/cron/%s HTTP/1.0\r\nHost: app\r\n\r\n" "$$STATIC_CRON_TOKEN"; sleep 300; } | nc -w 300 app 8080 > /dev/null 2>&1; sleep 82500; done'

    environment:
      STATIC_CRON_TOKEN: "{{ secret.STATIC_CRON_TOKEN }}"

    depends_on:
      app:
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
      MYSQL_DATABASE: firefly
      MYSQL_USER: firefly
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
  {{ volume.upload }}:
    external: true
  {{ volume.db }}:
    external: true
