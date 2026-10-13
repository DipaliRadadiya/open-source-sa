{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Unleash: feature-flag server and its private PostgreSQL (RC-38). --}}
name: {{ project }}

services:
  unleash:
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
      DATABASE_URL: "postgres://unleash:{{ secret.DB_PASSWORD }}@db:5432/unleash"
      DATABASE_SSL: "false"
      UNLEASH_URL: "{{ url }}"
      UNLEASH_DEFAULT_ADMIN_USERNAME: admin
      UNLEASH_DEFAULT_ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"
      SECURE_HEADERS: "true"
      SEND_TELEMETRY: "false"
      LOG_LEVEL: warn

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", "wget -q --spider http://localhost:4242/health || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 30s

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
      POSTGRES_DB: unleash
      POSTGRES_USER: unleash
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U unleash -d unleash"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.db }}:
    external: true
