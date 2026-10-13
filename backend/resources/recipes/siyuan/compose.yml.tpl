{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     SiYuan: one container, workspace in a named volume, locked by an access code (RC-30). --}}
name: {{ project }}

services:
  siyuan:
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
      SIYUAN_WORKSPACE_PATH: /siyuan/workspace
      SIYUAN_ACCESS_AUTH_CODE: "{{ secret.ACCESS_AUTH_CODE }}"
      PUID: "1000"
      PGID: "1000"
      TZ: Etc/UTC

    volumes:
      - {{ volume.workspace }}:/siyuan/workspace

volumes:
  {{ volume.workspace }}:
    external: true
