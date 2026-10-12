{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     LibreChat with a private MongoDB and Meilisearch (RC-27). --}}
name: {{ project }}

services:
  api:
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

    command: ["sh", "-c", "export CREDS_KEY=$$(printf %s $$CREDS_KEY_SEED | sha256sum | cut -c1-64) && export CREDS_IV=$$(printf %s $$CREDS_IV_SEED | sha256sum | cut -c1-32) && unset CREDS_KEY_SEED CREDS_IV_SEED && exec npm run backend"]
    environment:
      HOST: 0.0.0.0
      PORT: "3080"
      NODE_ENV: production
      MONGO_URI: "mongodb://librechat:{{ secret.MONGO_PASSWORD }}@mongodb:27017/LibreChat?authSource=admin"
      SEARCH: "true"
      MEILI_HOST: http://meilisearch:7700
      MEILI_MASTER_KEY: "{{ secret.MEILI_MASTER_KEY }}"
      MEILI_NO_ANALYTICS: "true"
      CREDS_KEY_SEED: "{{ secret.CREDS_KEY_SEED }}"
      CREDS_IV_SEED: "{{ secret.CREDS_IV_SEED }}"
      JWT_SECRET: "{{ secret.JWT_SECRET }}"
      JWT_REFRESH_SECRET: "{{ secret.JWT_REFRESH_SECRET }}"
      DOMAIN_CLIENT: "{{ url }}"
      DOMAIN_SERVER: "https://{{ domain }}"
      TRUST_PROXY: "1"
      NO_INDEX: "true"
      ALLOW_EMAIL_LOGIN: "true"
      ALLOW_REGISTRATION: "true"
      ALLOW_SOCIAL_LOGIN: "false"
      ALLOW_SOCIAL_REGISTRATION: "false"
      ALLOW_PASSWORD_RESET: "false"
      ALLOW_UNVERIFIED_EMAIL_LOGIN: "true"
      OPENAI_API_KEY: user_provided
      ANTHROPIC_API_KEY: user_provided
      GOOGLE_KEY: user_provided

    volumes:
      - {{ volume.data }}:/app/data
      - {{ volume.uploads }}:/app/uploads
      - {{ volume.images }}:/app/client/public/images

    depends_on:
      mongodb:
        condition: service_healthy
      meilisearch:
        condition: service_started

    healthcheck:
      test: ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1:3080/health || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 18
      start_period: 60s

  mongodb:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      MONGO_INITDB_ROOT_USERNAME: librechat
      MONGO_INITDB_ROOT_PASSWORD: "{{ secret.MONGO_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/data/db

    healthcheck:
      test: ["CMD", "mongosh", "--quiet", "--eval", "db.adminCommand('ping')"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 30s

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
      - {{ volume.search }}:/meili_data

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.uploads }}:
    external: true
  {{ volume.images }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.search }}:
    external: true
