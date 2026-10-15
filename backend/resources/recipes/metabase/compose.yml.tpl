{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Metabase: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  metabase:
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
      MB_DB_TYPE: postgres
      MB_DB_HOST: db
      MB_DB_PORT: 5432
      MB_DB_DBNAME: metabase
      MB_DB_USER: metabase
      MB_DB_PASS: {{ secret.DATABASE_PASSWORD }}

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

    environment:
      POSTGRES_DB: metabase
      POSTGRES_USER: metabase
      POSTGRES_PASSWORD: {{ secret.DATABASE_PASSWORD }}

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

volumes:
  {{ volume.db }}:
    external: true
