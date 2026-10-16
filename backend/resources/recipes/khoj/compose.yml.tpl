{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Khoj in single-user (anonymous) mode behind a Caddy password gate, copied
     from the searxng recipe: without an email service or Google sign-in Khoj
     has no web login of its own (RC-48). --}}
name: {{ project }}

services:
  proxy:
    image: {{ image.proxy }}
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

    entrypoint:
      - /bin/sh
      - -c
      - 'HASH=$$(caddy hash-password --plaintext "$$GATE_PASSWORD") && printf "{\n\tadmin off\n\tservers {\n\t\ttrusted_proxies static private_ranges\n\t}\n}\n:80 {\n\tbasic_auth {\n\t\tadmin %s\n\t}\n\treverse_proxy khoj:42110 {\n\t\theader_up -Authorization\n\t}\n}\n" "$$HASH" > /etc/caddy/Caddyfile && exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile'

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      GATE_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"

    depends_on:
      - khoj

  khoj:
    image: {{ image.khoj }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    working_dir: /app
    command: ["--host=0.0.0.0", "--port=42110", "--anonymous-mode", "--non-interactive"]
    environment:
      POSTGRES_DB: khoj
      POSTGRES_USER: khoj
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_HOST: database
      POSTGRES_PORT: "5432"
      KHOJ_DJANGO_SECRET_KEY: "{{ secret.KHOJ_DJANGO_SECRET_KEY }}"
      KHOJ_DEBUG: "False"
      KHOJ_ADMIN_EMAIL: "{{ input.admin_email }}"
      KHOJ_ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"
      KHOJ_DOMAIN: "{{ domain }}"
      KHOJ_TELEMETRY_DISABLE: "True"

    volumes:
      - {{ volume.config }}:/root/.khoj
      - {{ volume.models }}:/root/.cache

    depends_on:
      database:
        condition: service_healthy

  database:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      POSTGRES_DB: khoj
      POSTGRES_USER: khoj
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U khoj -d khoj"]
      interval: 10s
      timeout: 5s
      retries: 6

volumes:
  {{ volume.config }}:
    external: true
  {{ volume.models }}:
    external: true
  {{ volume.db }}:
    external: true
