{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Paperless-AI 3.0 behind a Caddy password gate, copied from the searxng
     recipe: upstream's /setup answers without a login even after setup and
     replaces the account (RC-47). --}}
name: {{ project }}

services:
  proxy:
    image: {{ image.proxy }}
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

    entrypoint:
      - /bin/sh
      - -c
      - 'HASH=$$(caddy hash-password --plaintext "$$GATE_PASSWORD") && printf "{\n\tadmin off\n}\n:80 {\n\tbasic_auth {\n\t\tadmin %s\n\t}\n\treverse_proxy paperlessai:3000 {\n\t\theader_up -Authorization\n\t}\n}\n" "$$HASH" > /etc/caddy/Caddyfile && exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile'

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      GATE_PASSWORD: "{{ secret.GATE_PASSWORD }}"

    depends_on:
      - paperlessai

  paperlessai:
    image: {{ image.paperlessai }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      PAPERLESS_AI_PORT: "3000"
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      RAG_SERVICE_URL: http://localhost:8000
      RAG_SERVICE_ENABLED: "true"
      HF_HOME: /app/data/huggingface

    volumes:
      - {{ volume.data }}:/app/data

volumes:
  {{ volume.data }}:
    external: true
