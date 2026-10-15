{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     code-server: VS Code in the browser, home directory in a named volume (RC-25). --}}
name: {{ project }}

services:
  code:
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
      PASSWORD: "{{ secret.PASSWORD }}"

    volumes:
      - {{ volume.home }}:/home/coder

    healthcheck:
      test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://localhost:8080/healthz"]
      interval: 10s
      timeout: 5s
      retries: 6
      start_period: 30s

volumes:
  {{ volume.home }}:
    external: true
