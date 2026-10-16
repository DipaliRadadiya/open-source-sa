{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Crawl4AI 0.9.5 API and playground with upstream's token auth on (RC-48).
     The browser pool limits live in the starter config.yml. --}}
name: {{ project }}

services:
  crawl4ai:
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

    shm_size: "1gb"
    cap_drop:
      - ALL

    environment:
      CRAWL4AI_API_TOKEN: "{{ secret.CRAWL4AI_API_TOKEN }}"
      SECRET_KEY: "{{ secret.SECRET_KEY }}"
      CRAWL4AI_ALLOW_DEEP_CRAWL: "false"
      FORWARDED_ALLOW_IPS: "*"

    volumes:
      - {{ site_root }}/app/config.yml:/app/config.yml:ro
