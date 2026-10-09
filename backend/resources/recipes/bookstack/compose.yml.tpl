{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     BookStack: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  app:
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

    {{-- --}}
    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: "UTC"
      APP_URL: "{{ url }}"
      APP_KEY: "base64:{{ secret.APP_KEY|base64 }}"
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: bookstack
      DB_USERNAME: bookstack
      DB_PASSWORD: "{{ secret.DATABASE_PASSWORD }}"

    {{-- --}}
    volumes:
      - {{ volume.config }}:/config

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
      MARIADB_DATABASE: bookstack
      MARIADB_USER: bookstack
      MARIADB_PASSWORD: "{{ secret.DATABASE_PASSWORD }}"
      MARIADB_ROOT_PASSWORD: "{{ secret.MARIADB_ROOT_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/mysql

volumes:
  {{ volume.config }}:
    external: true
  {{ volume.db }}:
    external: true
