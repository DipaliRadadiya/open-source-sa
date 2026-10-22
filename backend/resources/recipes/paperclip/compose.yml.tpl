{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Paperclip (authenticated, private) with a private PostgreSQL database (RC-49). --}}
name: {{ project }}

services:
  paperclip:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: {{ memory_limit }}
{{#if cpu_limit}}
    cpus: {{ cpu_limit }}
{{/if}}
    pids_limit: 2048
    stop_grace_period: 90s
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      DATABASE_URL: "postgres://paperclip:{{ secret.POSTGRES_PASSWORD }}@db:5432/paperclip"
      PAPERCLIP_DEPLOYMENT_MODE: authenticated
      PAPERCLIP_DEPLOYMENT_EXPOSURE: private
      PAPERCLIP_PUBLIC_URL: "{{ url }}"
      PAPERCLIP_AUTH_RATE_LIMIT_ENABLED: "true"
      PAPERCLIP_MIGRATION_AUTO_APPLY: "true"
      BETTER_AUTH_SECRET: "{{ secret.BETTER_AUTH_SECRET }}"
      PAPERCLIP_TOOL_ACTION_SIGNING_SECRET: "{{ secret.PAPERCLIP_TOOL_ACTION_SIGNING_SECRET }}"

    volumes:
      - {{ volume.data }}:/paperclip

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
      POSTGRES_DB: paperclip
      POSTGRES_USER: paperclip
      POSTGRES_PASSWORD: "{{ secret.POSTGRES_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U paperclip -d paperclip"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
