{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Langflow with authenticated access and private PostgreSQL (RC-16). --}}
name: {{ project }}

services:
  langflow:
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
      LANGFLOW_DATABASE_URL: "postgresql://langflow:{{ secret.DATABASE_PASSWORD }}@db:5432/langflow"
      LANGFLOW_CONFIG_DIR: /app/langflow
      LANGFLOW_AUTO_LOGIN: "False"
      LANGFLOW_SUPERUSER: admin
      LANGFLOW_SUPERUSER_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"
      LANGFLOW_SECRET_KEY: "{{ secret.LANGFLOW_SECRET_KEY }}"
      LANGFLOW_NEW_USER_IS_ACTIVE: "False"

    volumes:
      - {{ volume.data }}:/app/langflow

    depends_on:
      - db

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
      POSTGRES_DB: langflow
      POSTGRES_USER: langflow
      POSTGRES_PASSWORD: "{{ secret.DATABASE_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
