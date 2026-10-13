{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     YOURLS (Apache image) with private MariaDB, from the official image documentation (RC-36). --}}
name: {{ project }}

services:
  yourls:
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
      YOURLS_SITE: "{{ url }}"
      YOURLS_USER: admin
      YOURLS_PASS: "{{ secret.YOURLS_PASS }}"
      YOURLS_COOKIEKEY: "{{ secret.YOURLS_COOKIEKEY }}"
      YOURLS_DB_HOST: db
      YOURLS_DB_USER: yourls
      YOURLS_DB_PASS: "{{ secret.DB_PASSWORD }}"
      YOURLS_DB_NAME: yourls

    volumes:
      - {{ volume.user }}:/var/www/html/user
      - {{ site_root }}/docker-entrypoint-init.d:/docker-entrypoint-init.d:ro

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
      MYSQL_DATABASE: yourls
      MYSQL_USER: yourls
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
  {{ volume.user }}:
    external: true
  {{ volume.db }}:
    external: true
