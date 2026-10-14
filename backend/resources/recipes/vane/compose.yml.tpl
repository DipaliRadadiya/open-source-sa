{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Vane 1.12 (formerly Perplexica, with its bundled SearXNG) behind a Caddy
     password gate: Vane has no login of its own (RC-39). --}}
name: {{ project }}

services:
  proxy:
    image: {{ image.proxy }}
    restart: unless-stopped
    mem_limit: 128m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    entrypoint:
      - /bin/sh
      - -c
      - 'HASH=$$(caddy hash-password --plaintext "$$GATE_PASSWORD") && printf "{\n\tadmin off\n}\n:80 {\n\tbasic_auth {\n\t\tadmin %s\n\t}\n\treverse_proxy vane:3000 {\n\t\tflush_interval -1\n\t}\n}\n" "$$HASH" > /etc/caddy/Caddyfile && exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile'

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      GATE_PASSWORD: "{{ secret.GATE_PASSWORD }}"

    depends_on:
      - vane

  vane:
    image: {{ image.vane }}
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

    volumes:
      - {{ volume.data }}:/home/vane/data

volumes:
  {{ volume.data }}:
    external: true
