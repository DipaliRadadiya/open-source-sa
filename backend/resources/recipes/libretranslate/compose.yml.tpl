{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     LibreTranslate 1.9 (CPU image): web UI and API on one port, every
     translation needs an API key; the owner key is (re)written into the
     key database at each start (RC-47). --}}
name: {{ project }}

services:
  libretranslate:
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

    entrypoint:
      - /bin/bash
      - -c
      - 'touch /app/db/api_keys.db && ltmanage keys --api-keys-db-path /app/db/api_keys.db add 600 --key "$$LIBRETRANSLATE_API_KEY" >/dev/null && exec /app/scripts/entrypoint.sh'

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      LIBRETRANSLATE_API_KEY: "{{ secret.LIBRETRANSLATE_API_KEY }}"
      LT_API_KEYS: "true"
      LT_API_KEYS_DB_PATH: /app/db/api_keys.db
      LT_UNDER_ATTACK: "true"
      LT_LOAD_ONLY: en,es,de,fr,pt,ja,ru,hi
      LT_UPDATE_MODELS: "true"
      LT_THREADS: "2"

    volumes:
      - {{ volume.models }}:/home/libretranslate/.local
      - {{ volume.keys }}:/app/db

volumes:
  {{ volume.models }}:
    external: true
  {{ volume.keys }}:
    external: true
