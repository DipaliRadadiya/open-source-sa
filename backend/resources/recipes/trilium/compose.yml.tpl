{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Trilium Notes, one container, data in a named volume (RC-35). --}}
name: {{ project }}

services:
  trilium:
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
      TRILIUM_DATA_DIR: /home/node/trilium-data
      TZ: Etc/UTC

    volumes:
      - {{ volume.data }}:/home/node/trilium-data

volumes:
  {{ volume.data }}:
    external: true
