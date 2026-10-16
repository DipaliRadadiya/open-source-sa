{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     SearXNG metasearch behind a Caddy password gate, copied from the vane
     recipe: SearXNG has no login of its own (RC-46). --}}
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
      - 'HASH=$$(caddy hash-password --plaintext "$$GATE_PASSWORD") && printf "{\n\tadmin off\n}\n:80 {\n\tbasic_auth {\n\t\tadmin %s\n\t}\n\treverse_proxy searxng:8080\n}\n" "$$HASH" > /etc/caddy/Caddyfile && exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile'

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      GATE_PASSWORD: "{{ secret.GATE_PASSWORD }}"

    depends_on:
      - searxng

  searxng:
    image: {{ image.searxng }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      SEARXNG_SECRET: "{{ secret.SEARXNG_SECRET }}"
      SEARXNG_BASE_URL: https://{{ domain }}/
      FORCE_OWNERSHIP: "false"

    volumes:
      - {{ site_root }}/etc/searxng:/etc/searxng:ro
      - {{ volume.data }}:/var/cache/searxng

volumes:
  {{ volume.data }}:
    external: true
