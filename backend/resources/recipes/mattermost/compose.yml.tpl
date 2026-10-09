{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Mattermost: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  mattermost:
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
      {{-- --}}
      MM_SERVICESETTINGS_SITEURL: {{ url }}
      MM_SQLSETTINGS_DRIVERNAME: postgres
      {{-- --}}
      MM_SQLSETTINGS_DATASOURCE: "postgres://mattermost:{{ secret.DATABASE_PASSWORD }}@db:5432/mattermost?sslmode=disable&connect_timeout=10"

    volumes:
      - {{ volume.data }}:/mattermost/data
      - {{ volume.config }}:/mattermost/config
      - {{ volume.plugins }}:/mattermost/plugins
      {{-- --}}
      - {{ volume.client-plugins }}:/mattermost/client/plugins
      - {{ volume.logs }}:/mattermost/logs

    depends_on:
      - db

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
      POSTGRES_DB: mattermost
      POSTGRES_USER: mattermost
      POSTGRES_PASSWORD: {{ secret.DATABASE_PASSWORD }}

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.config }}:
    external: true
  {{ volume.plugins }}:
    external: true
  {{ volume.client-plugins }}:
    external: true
  {{ volume.logs }}:
    external: true
  {{ volume.db }}:
    external: true
