{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Actual Budget sync server (RC-32). --}}
name: {{ project }}

services:
  actual:
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

    volumes:
      - {{ volume.data }}:/data

    healthcheck:
      test: ["CMD-SHELL", "node scripts/health-check.js"]
      interval: 60s
      timeout: 10s
      retries: 3
      start_period: 20s

volumes:
  {{ volume.data }}:
    external: true
