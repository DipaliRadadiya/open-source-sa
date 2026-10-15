{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     NocoDB: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  nocodb:
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
      {{-- --}}
      NC_PUBLIC_URL: {{ url }}
      NC_DB: "pg://db:5432?u=nocodb&p={{ secret.DATABASE_PASSWORD }}&d=nocodb"

    volumes:
      - {{ volume.data }}:/usr/app/data

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
      POSTGRES_DB: nocodb
      POSTGRES_USER: nocodb
      POSTGRES_PASSWORD: {{ secret.DATABASE_PASSWORD }}

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
