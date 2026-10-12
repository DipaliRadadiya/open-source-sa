{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Penpot 2.18.3: frontend, backend, exporter, private PostgreSQL and Valkey (RC-18). --}}
name: {{ project }}

services:
  penpot-frontend:
    image: {{ image.frontend }}
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
      PENPOT_FLAGS: enable-registration enable-login-with-password disable-email-verification disable-smtp
      PENPOT_PUBLIC_URI: "{{ url }}"
      PENPOT_HTTP_SERVER_MAX_BODY_SIZE: "367001600"
      PENPOT_HTTP_SERVER_MAX_MULTIPART_BODY_SIZE: "367001600"

    volumes:
      - {{ volume.assets }}:/opt/data/assets

    depends_on:
      - penpot-backend
      - penpot-exporter

  penpot-backend:
    image: {{ image.backend }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      PENPOT_FLAGS: enable-registration enable-login-with-password disable-email-verification disable-smtp
      PENPOT_PUBLIC_URI: "{{ url }}"
      PENPOT_HTTP_SERVER_MAX_BODY_SIZE: "367001600"
      PENPOT_HTTP_SERVER_MAX_MULTIPART_BODY_SIZE: "367001600"
      PENPOT_SECRET_KEY: "{{ secret.PENPOT_SECRET_KEY }}"
      PENPOT_DATABASE_URI: postgresql://penpot-postgres/penpot
      PENPOT_DATABASE_USERNAME: penpot
      PENPOT_DATABASE_PASSWORD: "{{ secret.DB_PASSWORD }}"
      PENPOT_REDIS_URI: redis://penpot-valkey/0
      PENPOT_OBJECTS_STORAGE_BACKEND: fs
      PENPOT_OBJECTS_STORAGE_FS_DIRECTORY: /opt/data/assets

    volumes:
      - {{ volume.assets }}:/opt/data/assets

    depends_on:
      penpot-postgres:
        condition: service_healthy
      penpot-valkey:
        condition: service_healthy

  penpot-exporter:
    image: {{ image.exporter }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      PENPOT_FLAGS: enable-registration enable-login-with-password disable-email-verification disable-smtp
      PENPOT_PUBLIC_URI: "{{ url }}"
      PENPOT_SECRET_KEY: "{{ secret.PENPOT_SECRET_KEY }}"
      PENPOT_INTERNAL_URI: http://penpot-frontend:8080
      PENPOT_REDIS_URI: redis://penpot-valkey/0

    depends_on:
      penpot-valkey:
        condition: service_healthy

  penpot-postgres:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    stop_signal: SIGINT
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      POSTGRES_INITDB_ARGS: "--data-checksums"
      POSTGRES_DB: penpot
      POSTGRES_USER: penpot
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U penpot"]
      interval: 2s
      timeout: 10s
      retries: 5
      start_period: 2s

  penpot-valkey:
    image: {{ image.cache }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["valkey-server", "--maxmemory", "128mb", "--maxmemory-policy", "volatile-lfu"]

    healthcheck:
      test: ["CMD-SHELL", "valkey-cli ping | grep PONG"]
      interval: 1s
      timeout: 3s
      retries: 5
      start_period: 3s

volumes:
  {{ volume.assets }}:
    external: true
  {{ volume.db }}:
    external: true
