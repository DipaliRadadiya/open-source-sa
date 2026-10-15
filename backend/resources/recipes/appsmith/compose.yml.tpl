{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Appsmith Community all-in-one container (embedded MongoDB and Redis) (RC-27). --}}
name: {{ project }}

services:
  appsmith:
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
      APPSMITH_ENCRYPTION_PASSWORD: "{{ secret.APPSMITH_ENCRYPTION_PASSWORD }}"
      APPSMITH_ENCRYPTION_SALT: "{{ secret.APPSMITH_ENCRYPTION_SALT }}"
      APPSMITH_DISABLE_TELEMETRY: "true"

    volumes:
      - {{ volume.stacks }}:/appsmith-stacks

    healthcheck:
      test: ["CMD", "/opt/appsmith/healthcheck.sh"]
      interval: 15s
      timeout: 15s
      retries: 20
      start_period: 300s

volumes:
  {{ volume.stacks }}:
    external: true
