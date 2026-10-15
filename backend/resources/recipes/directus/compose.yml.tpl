{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Directus 12 with a private PostgreSQL database (RC-25). --}}
name: {{ project }}

services:
  directus:
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
      SECRET: "{{ secret.SECRET }}"
      PUBLIC_URL: "{{ url }}"
      DB_CLIENT: pg
      DB_HOST: db
      DB_PORT: "5432"
      DB_DATABASE: directus
      DB_USER: directus
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      ADMIN_EMAIL: "{{ input.admin_email }}"
      ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"

    volumes:
      - {{ volume.uploads }}:/directus/uploads
      - {{ volume.extensions }}:/directus/extensions

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", "wget --spider -q http://127.0.0.1:8055/server/ping || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 60s

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
      POSTGRES_DB: directus
      POSTGRES_USER: directus
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "--host=localhost", "--username=directus", "--dbname=directus"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.uploads }}:
    external: true
  {{ volume.extensions }}:
    external: true
  {{ volume.db }}:
    external: true
