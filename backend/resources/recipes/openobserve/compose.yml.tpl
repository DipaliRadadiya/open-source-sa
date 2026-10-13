{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     OpenObserve: one container, logs/metrics/traces stored on local disk in /data (RC-38). --}}
name: {{ project }}

services:
  openobserve:
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
      ZO_ROOT_USER_EMAIL: "{{ input.admin_email }}"
      ZO_ROOT_USER_PASSWORD: "{{ secret.ROOT_PASSWORD }}"
      ZO_DATA_DIR: /data
      ZO_WEB_URL: "{{ url }}"
      ZO_COOKIE_SECURE_ONLY: "true"
      ZO_TELEMETRY: "false"

    volumes:
      - {{ volume.data }}:/data

    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://localhost:5080/healthz || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 30s

volumes:
  {{ volume.data }}:
    external: true
