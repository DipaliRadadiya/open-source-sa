{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Open WebUI 0.12 (slim), without a bundled Ollama (RC-39). --}}
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
      ENABLE_OLLAMA_API: "false"
      WEBUI_SECRET_KEY: "{{ secret.WEBUI_SECRET_KEY }}"
      WEBUI_ADMIN_EMAIL: "{{ input.admin_email }}"
      WEBUI_ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"
      WEBUI_ADMIN_NAME: Admin
      WEBUI_URL: "{{ url }}"

    volumes:
      - {{ volume.data }}:/app/backend/data

volumes:
  {{ volume.data }}:
    external: true
