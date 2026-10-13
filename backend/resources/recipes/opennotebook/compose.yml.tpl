{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Open Notebook with its private SurrealDB database (RC-35). --}}
name: {{ project }}

services:
  open_notebook:
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
      API_URL: "{{ url }}"
      OPEN_NOTEBOOK_PASSWORD: "{{ secret.OPEN_NOTEBOOK_PASSWORD }}"
      OPEN_NOTEBOOK_ENCRYPTION_KEY: "{{ secret.OPEN_NOTEBOOK_ENCRYPTION_KEY }}"
      SURREAL_URL: ws://surrealdb:8000/rpc
      SURREAL_USER: "{{ secret.SURREAL_USER }}"
      SURREAL_PASSWORD: "{{ secret.SURREAL_PASSWORD }}"
      SURREAL_NAMESPACE: open_notebook
      SURREAL_DATABASE: open_notebook
      CORS_ORIGINS: "https://{{ domain }}"

    volumes:
      - {{ volume.data }}:/app/data

    depends_on:
      surrealdb:
        condition: service_started

    healthcheck:
      test: ["CMD-SHELL", "curl -fsS -o /dev/null http://127.0.0.1:5055/health && curl -fsS -o /dev/null http://127.0.0.1:8502/"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 600s

  surrealdb:
    image: {{ image.db }}
    user: root
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["start", "--log", "info", "rocksdb:/mydata/mydatabase.db"]
    environment:
      SURREAL_USER: "{{ secret.SURREAL_USER }}"
      SURREAL_PASS: "{{ secret.SURREAL_PASSWORD }}"
      SURREAL_EXPERIMENTAL_GRAPHQL: "true"

    volumes:
      - {{ volume.db }}:/mydata

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
