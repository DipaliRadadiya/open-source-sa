{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Immich v3.3.1 with CPU machine learning and private Valkey/PostgreSQL (RC-18). --}}
name: {{ project }}

services:
  immich-server:
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
      TZ: Etc/UTC
      DB_PASSWORD: "{{ secret.DB_PASSWORD }}"
      DB_USERNAME: postgres
      DB_DATABASE_NAME: immich
      DB_HOSTNAME: database
      REDIS_HOSTNAME: redis

    volumes:
      - {{ volume.upload }}:/data

    depends_on:
      redis:
        condition: service_healthy
      database:
        condition: service_healthy

    healthcheck:
      disable: false

  immich-machine-learning:
    image: {{ image.ml }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      TZ: Etc/UTC

    volumes:
      - {{ volume.model-cache }}:/cache

    healthcheck:
      disable: false

  redis:
    image: {{ image.cache }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    healthcheck:
      test: ["CMD-SHELL", "redis-cli ping | grep -q PONG || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 10

  database:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: 2g
    shm_size: 128mb
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"
      POSTGRES_USER: postgres
      POSTGRES_DB: immich
      POSTGRES_INITDB_ARGS: '--data-checksums'

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      disable: false

volumes:
  {{ volume.upload }}:
    external: true
  {{ volume.model-cache }}:
    external: true
  {{ volume.db }}:
    external: true
