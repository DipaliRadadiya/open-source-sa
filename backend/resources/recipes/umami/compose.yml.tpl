{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Umami v3: analytics application and private PostgreSQL (RC-16). --}}
name: {{ project }}

services:
  umami:
    image: {{ image.app }}
    init: true
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
      DATABASE_URL: "postgresql://umami:{{ secret.DATABASE_PASSWORD }}@db:5432/umami"
      APP_SECRET: "{{ secret.APP_SECRET }}"

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", "curl --fail http://localhost:3000/api/heartbeat"]
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
      POSTGRES_DB: umami
      POSTGRES_USER: umami
      POSTGRES_PASSWORD: "{{ secret.DATABASE_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.db }}:
    external: true
