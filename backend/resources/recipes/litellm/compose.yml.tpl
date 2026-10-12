{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     LiteLLM proxy and admin UI with a private PostgreSQL database (RC-26). --}}
name: {{ project }}

services:
  litellm:
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
      DATABASE_URL: "postgresql://litellm:{{ secret.DB_PASSWORD }}@db:5432/litellm"
      STORE_MODEL_IN_DB: "True"
      LITELLM_MASTER_KEY: "sk-{{ secret.MASTER_KEY }}"
      LITELLM_SALT_KEY: "sk-{{ secret.SALT_KEY }}"
      UI_USERNAME: admin
      UI_PASSWORD: "{{ secret.UI_PASSWORD }}"

    depends_on:
      db:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "python3", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:4000/health/liveliness')"]
      interval: 15s
      timeout: 10s
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
      POSTGRES_DB: litellm
      POSTGRES_USER: litellm
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U litellm -d litellm"]
      interval: 10s
      timeout: 5s
      retries: 6

volumes:
  {{ volume.db }}:
    external: true
