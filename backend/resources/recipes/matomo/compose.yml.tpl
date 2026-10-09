{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Matomo: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  matomo:
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
      MATOMO_DATABASE_HOST: db
      MATOMO_DATABASE_ADAPTER: mysql
      MATOMO_DATABASE_DBNAME: matomo
      MATOMO_DATABASE_USERNAME: matomo
      MATOMO_DATABASE_PASSWORD: {{ secret.DATABASE_PASSWORD }}

    volumes:
      - {{ volume.app }}:/var/www/html

    depends_on:
      - db

  db:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    {{-- --}}
    command: --max-allowed-packet=64MB

    environment:
      MARIADB_DATABASE: matomo
      MARIADB_USER: matomo
      MARIADB_PASSWORD: {{ secret.DATABASE_PASSWORD }}
      MARIADB_ROOT_PASSWORD: {{ secret.MARIADB_ROOT_PASSWORD }}

    volumes:
      - {{ volume.db }}:/var/lib/mysql

volumes:
  {{ volume.app }}:
    external: true
  {{ volume.db }}:
    external: true
