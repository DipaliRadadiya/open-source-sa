{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     marimo notebook editor, one container, locked by a password (RC-35). --}}
name: {{ project }}

services:
  marimo:
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

    command: ["sh", "-c", "exec marimo -q edit --headless --host 0.0.0.0 --port 8080 --token-password-file - --skip-update-check --no-sandbox /app/data <<EOF\n$$MARIMO_PASSWORD\nEOF"]
    environment:
      MARIMO_PASSWORD: "{{ secret.MARIMO_PASSWORD }}"

    volumes:
      - {{ volume.notebooks }}:/app/data

    healthcheck:
      test: ["CMD", "python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=5)"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 120s

volumes:
  {{ volume.notebooks }}:
    external: true
