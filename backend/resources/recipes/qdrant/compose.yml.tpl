{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Qdrant 1.19 vector database (unprivileged image), REST and dashboard
     behind its own API key (RC-46). --}}
name: {{ project }}

services:
  qdrant:
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
      QDRANT__SERVICE__API_KEY: "{{ secret.QDRANT__SERVICE__API_KEY }}"
      QDRANT__STORAGE__SNAPSHOTS_PATH: /qdrant/storage/snapshots
      QDRANT__TELEMETRY_DISABLED: "true"

    volumes:
      - {{ volume.data }}:/qdrant/storage

volumes:
  {{ volume.data }}:
    external: true
