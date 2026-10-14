{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Hoppscotch all-in-one image in sub-path mode with a private PostgreSQL database (RC-40). --}}
name: {{ project }}

services:
  hoppscotch:
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

    command: ["sh", "-c", "pnpm exec prisma migrate deploy && exec node /usr/src/app/aio_run.mjs"]

    environment:
      DATABASE_URL: "postgresql://hoppscotch:{{ secret.DB_PASSWORD }}@db:5432/hoppscotch?connect_timeout=300"
      DATA_ENCRYPTION_KEY: "{{ secret.DATA_ENCRYPTION_KEY }}"
      WHITELISTED_ORIGINS: "https://{{ domain }},app://localhost_3200,app://hoppscotch"
      TRUST_PROXY: "true"
      ENABLE_SUBPATH_BASED_ACCESS: "true"
      VITE_BASE_URL: "{{ url }}"
      VITE_SHORTCODE_BASE_URL: "https://{{ domain }}"
      VITE_ADMIN_URL: "https://{{ domain }}/admin"
      VITE_BACKEND_GQL_URL: "https://{{ domain }}/backend/graphql"
      VITE_BACKEND_WS_URL: "wss://{{ domain }}/backend/graphql"
      VITE_BACKEND_API_URL: "https://{{ domain }}/backend/v1"
      VITE_APP_TOS_LINK: https://docs.hoppscotch.io/support/terms
      VITE_APP_PRIVACY_POLICY_LINK: https://docs.hoppscotch.io/support/privacy

    volumes:
      - {{ volume.data }}:/data

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:80/backend/ping"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 120s

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
      POSTGRES_DB: hoppscotch
      POSTGRES_USER: hoppscotch
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "hoppscotch", "-d", "hoppscotch"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
