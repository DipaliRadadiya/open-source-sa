{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Listmonk with a private PostgreSQL database (RC-25). --}}
name: {{ project }}

services:
  listmonk:
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

    command: ["sh", "-c", "./listmonk --install --idempotent --yes --config '' && ./listmonk --upgrade --yes --config '' && ./listmonk --config ''"]
    environment:
      LISTMONK_app__address: "0.0.0.0:9000"
      LISTMONK_db__user: listmonk
      LISTMONK_db__password: "{{ secret.DB_PASSWORD }}"
      LISTMONK_db__database: listmonk
      LISTMONK_db__host: db
      LISTMONK_db__port: "5432"
      LISTMONK_db__ssl_mode: disable
      LISTMONK_db__max_open: "25"
      LISTMONK_db__max_idle: "25"
      LISTMONK_db__max_lifetime: 300s
      TZ: Etc/UTC
      LISTMONK_ADMIN_USER: admin
      LISTMONK_ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"

    volumes:
      - {{ volume.uploads }}:/listmonk/uploads

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", "wget -q -O /dev/null http://localhost:9000/health || exit 1"]
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
      POSTGRES_DB: listmonk
      POSTGRES_USER: listmonk
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U listmonk -d listmonk"]
      interval: 10s
      timeout: 5s
      retries: 6

volumes:
  {{ volume.uploads }}:
    external: true
  {{ volume.db }}:
    external: true
