{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Meilisearch search engine API (RC-31). --}}
name: {{ project }}

services:
  meilisearch:
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
      MEILI_ENV: production
      MEILI_MASTER_KEY: "{{ secret.MEILI_MASTER_KEY }}"
      MEILI_NO_ANALYTICS: "true"

    volumes:
      - {{ volume.data }}:/meili_data

    healthcheck:
      test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:7700/health"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 20s

volumes:
  {{ volume.data }}:
    external: true
