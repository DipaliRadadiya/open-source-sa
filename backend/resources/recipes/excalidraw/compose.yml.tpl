{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Excalidraw: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  app:
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

    {{-- --}}
    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"
