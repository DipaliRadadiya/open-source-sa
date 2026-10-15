{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Gotify: one container with its SQLite database, images and plugins in /app/data (RC-38). --}}
name: {{ project }}

services:
  gotify:
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
      GOTIFY_DEFAULTUSER_NAME: admin
      GOTIFY_DEFAULTUSER_PASS: "{{ secret.ADMIN_PASSWORD }}"
      GOTIFY_SERVER_SECURECOOKIE: "true"
      GOTIFY_REGISTRATION: "false"

    volumes:
      - {{ volume.data }}:/app/data

volumes:
  {{ volume.data }}:
    external: true
