{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Paperless-ngx: webserver, Redis broker and PostgreSQL (RC-09). --}}
name: {{ project }}

services:
  webserver:
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
      PAPERLESS_REDIS: redis://broker:6379
      PAPERLESS_DBENGINE: postgresql
      PAPERLESS_DBHOST: db
      PAPERLESS_DBPASS: "{{ secret.DATABASE_PASSWORD }}"
      PAPERLESS_SECRET_KEY: "{{ secret.PAPERLESS_SECRET_KEY }}"
      PAPERLESS_URL: "{{ url }}"
      PAPERLESS_ADMIN_USER: admin
      PAPERLESS_ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"

    volumes:
      - {{ volume.data }}:/usr/src/paperless/data
      - {{ volume.media }}:/usr/src/paperless/media

    depends_on:
      - broker
      - db

  broker:
    image: {{ image.broker }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    volumes:
      - {{ volume.broker }}:/data

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
      POSTGRES_DB: paperless
      POSTGRES_USER: paperless
      POSTGRES_PASSWORD: "{{ secret.DATABASE_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.media }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.broker }}:
    external: true
