{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Joplin Server with a private PostgreSQL database (RC-30). --}}
name: {{ project }}

services:
  joplin:
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
      APP_BASE_URL: "{{ url }}"
      APP_PORT: "22300"
      DB_CLIENT: pg
      POSTGRES_HOST: db
      POSTGRES_PORT: "5432"
      POSTGRES_DATABASE: joplin
      POSTGRES_USER: joplin
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "node", "-e", "fetch('http://127.0.0.1:22300/api/ping').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"]
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
      POSTGRES_DB: joplin
      POSTGRES_USER: joplin
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "joplin", "-d", "joplin"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.db }}:
    external: true
