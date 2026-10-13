{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Apache Answer: one container with its SQLite database in /data, installed by the image's own AUTO_INSTALL (RC-37). --}}
name: {{ project }}

services:
  answer:
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
      AUTO_INSTALL: "true"
      DB_TYPE: sqlite3
      DB_FILE: /data/answer.db
      LANGUAGE: en_US
      SITE_NAME: Answer
      SITE_URL: "{{ url }}"
      CONTACT_EMAIL: "{{ input.admin_email }}"
      EXTERNAL_CONTENT_DISPLAY: always_display
      ADMIN_NAME: admin
      ADMIN_EMAIL: "{{ input.admin_email }}"
      ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"

    volumes:
      - {{ volume.data }}:/data

volumes:
  {{ volume.data }}:
    external: true
