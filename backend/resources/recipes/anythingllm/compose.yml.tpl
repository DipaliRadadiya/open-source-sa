{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     AnythingLLM 1.18 (default LanceDB build), password-protected from the first start (RC-45). --}}
name: {{ project }}

services:
  anythingllm:
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

    user: "0:0"
    environment:
      HOME: /app
      STORAGE_DIR: /app/server/storage
      SERVER_PORT: "3001"
      AUTH_TOKEN: "{{ secret.AUTH_TOKEN }}"
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      ANYTHINGLLM_CHROMIUM_ARGS: "--no-sandbox,--disable-setuid-sandbox,--disable-dev-shm-usage"
      DISABLE_TELEMETRY: "true"

    entrypoint:
      - /bin/bash
      - -ec
      - |
        S=/app/server/storage
        find "$$S" ! -user anythingllm -exec chown anythingllm:anythingllm {} +
        if [ ! -f "$$S/.env" ]; then
          ( umask 077; printf "AUTH_TOKEN='%s'\nJWT_SECRET='%s'\n" "$$AUTH_TOKEN" "$$JWT_SECRET" > "$$S/.env" )
          chown anythingllm:anythingllm "$$S/.env"
        fi
        ln -sfn "$$S/.env" /app/server/.env
        unset AUTH_TOKEN JWT_SECRET
        exec setpriv --reuid=anythingllm --regid=anythingllm --init-groups /bin/bash /usr/local/bin/docker-entrypoint.sh

    volumes:
      - {{ volume.data }}:/app/server/storage

volumes:
  {{ volume.data }}:
    external: true
