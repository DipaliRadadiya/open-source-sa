{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Agent Zero v2.14 behind the panel's HTTPS proxy, login required from the first start (RC-45). --}}
name: {{ project }}

services:
  agentzero:
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
      AUTH_LOGIN: admin
      AUTH_PASSWORD: "{{ secret.AUTH_PASSWORD }}"
      ALLOWED_ORIGINS: "https://{{ domain }}"

    volumes:
      - {{ volume.data }}:/a0/usr

volumes:
  {{ volume.data }}:
    external: true
