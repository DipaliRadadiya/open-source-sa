{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Open WebUI with a private CPU-only Ollama (RC-16). --}}
name: {{ project }}

services:
  openwebui:
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
      OLLAMA_BASE_URL: http://ollama:11434
      WEBUI_SECRET_KEY: "{{ secret.WEBUI_SECRET_KEY }}"
      WEBUI_ADMIN_EMAIL: "{{ input.admin_email }}"
      WEBUI_ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"
      WEBUI_ADMIN_NAME: Admin
      WEBUI_URL: "{{ url }}"

    volumes:
      - {{ volume.data }}:/app/backend/data

    depends_on:
      - ollama

  ollama:
    image: {{ image.ollama }}
    restart: unless-stopped
    mem_limit: 4g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    volumes:
      - {{ volume.ollama }}:/root/.ollama

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.ollama }}:
    external: true
