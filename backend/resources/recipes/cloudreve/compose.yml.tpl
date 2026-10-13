{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Cloudreve v4 with private PostgreSQL and Redis (RC-31). --}}
name: {{ project }}

services:
  cloudreve:
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
      CR_SETTING_DEFAULT_siteURL: "{{ url }}"
      CR_CONF_Database__Type: postgres
      CR_CONF_Database__Host: db
      CR_CONF_Database__Port: "5432"
      CR_CONF_Database__User: cloudreve
      CR_CONF_Database__Password: "{{ secret.DB_PASSWORD }}"
      CR_CONF_Database__Name: cloudreve
      CR_CONF_Redis__Server: redis:6379
      TZ: UTC

    volumes:
      - {{ volume.data }}:/cloudreve/data

    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy

    healthcheck:
      test: ["CMD", "wget", "-q", "-O", "/dev/null", "http://127.0.0.1:5212/api/v4/site/ping"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 30s

  db:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      POSTGRES_DB: cloudreve
      POSTGRES_USER: cloudreve
      POSTGRES_PASSWORD: "{{ secret.DB_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD", "pg_isready", "-U", "cloudreve", "-d", "cloudreve"]
      interval: 5s
      timeout: 5s
      retries: 12

  redis:
    image: {{ image.cache }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    volumes:
      - {{ volume.redis }}:/data

    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
