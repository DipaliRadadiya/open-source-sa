{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     DocuSeal with a private PostgreSQL database (RC-25). --}}
name: {{ project }}

services:
  docuseal:
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
      DATABASE_URL: "postgresql://docuseal:{{ secret.DB_PASSWORD }}@db:5432/docuseal"
      SECRET_KEY_BASE: "{{ secret.SECRET_KEY_BASE }}"
      HOST: "{{ domain }}"

    volumes:
      - {{ volume.data }}:/data/docuseal

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1:3000/up || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 90s

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
      POSTGRES_DB: docuseal
      POSTGRES_USER: docuseal
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "docuseal", "-d", "docuseal"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
