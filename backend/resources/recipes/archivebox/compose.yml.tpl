{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     ArchiveBox, one container, private by default (RC-35). --}}
name: {{ project }}

services:
  archivebox:
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

    command: ["server", "--quick-init", "0.0.0.0:8000"]
    environment:
      ADMIN_USERNAME: admin
      ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"
      ALLOWED_HOSTS: "{{ domain }}"
      PUBLIC_INDEX: "False"
      PUBLIC_SNAPSHOTS: "False"
      PUBLIC_ADD_VIEW: "False"

    volumes:
      - {{ volume.data }}:/data

    healthcheck:
      test: ["CMD-SHELL", "curl -fsS -o /dev/null -H 'Host: {{ domain }}' http://127.0.0.1:8000/admin/login/"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 300s

volumes:
  {{ volume.data }}:
    external: true
