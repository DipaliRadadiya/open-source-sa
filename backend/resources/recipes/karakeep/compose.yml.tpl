{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Karakeep with its headless Chrome crawler and Meilisearch (RC-30). --}}
name: {{ project }}

services:
  web:
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
      NEXTAUTH_URL: "{{ url }}"
      NEXTAUTH_SECRET: "{{ secret.NEXTAUTH_SECRET }}"
      MEILI_ADDR: http://meilisearch:7700
      MEILI_MASTER_KEY: "{{ secret.MEILI_MASTER_KEY }}"
      BROWSER_WEB_URL: http://chrome:9222
      DATA_DIR: /data
      DB_WAL_MODE: "true"
      DISABLE_SIGNUPS: "false"

    volumes:
      - {{ volume.data }}:/data

    depends_on:
      meilisearch:
        condition: service_started
      chrome:
        condition: service_started

  chrome:
    image: {{ image.chrome }}
    init: true
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command:
      - --disable-gpu
      - --disable-dev-shm-usage
      - --hide-scrollbars
      - --disable-blink-features=AutomationControlled
      - --window-size=1440,900

  meilisearch:
    image: {{ image.search }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      MEILI_ENV: production
      MEILI_MASTER_KEY: "{{ secret.MEILI_MASTER_KEY }}"
      MEILI_NO_ANALYTICS: "true"

    volumes:
      - {{ volume.meili }}:/meili_data

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.meili }}:
    external: true
