{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     TREK: one container with its SQLite database, from the official compose file (RC-36). --}}
name: {{ project }}

services:
  trek:
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

    command:
      - sh
      - -c
      - '(if [ -f /trek-init/close-registration.cjs ]; then n=0; until gosu node node /trek-init/close-registration.cjs || [ $$n -ge 120 ]; do n=$$((n + 1)); sleep 5; done; fi &); exec /usr/local/bin/trek-entrypoint'

    environment:
      NODE_ENV: production
      PORT: "3000"
      TZ: UTC
      LOG_LEVEL: info
      APP_URL: "{{ url }}"
      ALLOWED_ORIGINS: "https://{{ domain }}"
      TRUST_PROXY: "1"
      FORCE_HTTPS: "true"
      ENCRYPTION_KEY: "{{ secret.ENCRYPTION_KEY }}"
      ADMIN_EMAIL: "{{ input.admin_email }}"
      ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"

    volumes:
      - {{ volume.data }}:/app/data
      - {{ volume.uploads }}:/app/uploads
      - {{ site_root }}/trek-init:/trek-init:ro

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.uploads }}:
    external: true
