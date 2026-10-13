{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Mautic 7 (Apache image) with its cron and worker roles and a private MariaDB, from the official docker-mautic basic example (RC-37). --}}
name: {{ project }}

services:
  mautic:
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

    entrypoint:
      - /bin/bash
      - -c
      - 'if [ -f /mautic-init/start.sh ]; then exec /bin/bash /mautic-init/start.sh; fi; exec /entrypoint.sh'

    environment:
      DOCKER_MAUTIC_ROLE: mautic_web
      MAUTIC_URL: "{{ url }}"
      MAUTIC_ADMIN_EMAIL: "{{ input.admin_email }}"
      MAUTIC_ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"
      MAUTIC_DB_HOST: db
      MAUTIC_DB_PORT: "3306"
      MAUTIC_DB_DATABASE: mautic
      MAUTIC_DB_USER: mautic
      MAUTIC_DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MAUTIC_MESSENGER_DSN_EMAIL: doctrine://default
      MAUTIC_MESSENGER_DSN_HIT: doctrine://default

    volumes:
      - {{ volume.config }}:/var/www/html/config
      - {{ volume.logs }}:/var/www/html/var/logs
      - {{ volume.files }}:/var/www/html/docroot/media/files
      - {{ volume.images }}:/var/www/html/docroot/media/images
      - {{ site_root }}/mautic-init:/mautic-init:ro

    depends_on:
      db:
        condition: service_healthy

  cron:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      DOCKER_MAUTIC_ROLE: mautic_cron
      MAUTIC_DB_HOST: db
      MAUTIC_DB_PORT: "3306"
      MAUTIC_DB_DATABASE: mautic
      MAUTIC_DB_USER: mautic
      MAUTIC_DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MAUTIC_MESSENGER_DSN_EMAIL: doctrine://default
      MAUTIC_MESSENGER_DSN_HIT: doctrine://default

    volumes:
      - {{ volume.config }}:/var/www/html/config
      - {{ volume.logs }}:/var/www/html/var/logs
      - {{ volume.files }}:/var/www/html/docroot/media/files
      - {{ volume.images }}:/var/www/html/docroot/media/images

    depends_on:
      mautic:
        condition: service_started

  worker:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: 768m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      DOCKER_MAUTIC_ROLE: mautic_worker
      DOCKER_MAUTIC_WORKERS_CONSUME_EMAIL: "1"
      DOCKER_MAUTIC_WORKERS_CONSUME_HIT: "1"
      DOCKER_MAUTIC_WORKERS_CONSUME_FAILED: "1"
      MAUTIC_DB_HOST: db
      MAUTIC_DB_PORT: "3306"
      MAUTIC_DB_DATABASE: mautic
      MAUTIC_DB_USER: mautic
      MAUTIC_DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MAUTIC_MESSENGER_DSN_EMAIL: doctrine://default
      MAUTIC_MESSENGER_DSN_HIT: doctrine://default

    volumes:
      - {{ volume.config }}:/var/www/html/config
      - {{ volume.logs }}:/var/www/html/var/logs
      - {{ volume.files }}:/var/www/html/docroot/media/files
      - {{ volume.images }}:/var/www/html/docroot/media/images

    depends_on:
      mautic:
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
      MYSQL_DATABASE: mautic
      MYSQL_USER: mautic
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
  {{ volume.config }}:
    external: true
  {{ volume.logs }}:
    external: true
  {{ volume.files }}:
    external: true
  {{ volume.images }}:
    external: true
  {{ volume.db }}:
    external: true
