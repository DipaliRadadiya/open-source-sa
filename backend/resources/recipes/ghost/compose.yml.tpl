{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Ghost: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  ghost:
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
      {{-- --}}
      url: {{ url }}
      NODE_ENV: production
      database__client: mysql
      database__connection__host: db
      database__connection__user: ghost
      database__connection__password: {{ secret.GHOST_DB_PASSWORD }}
      database__connection__database: ghost

    volumes:
      - {{ volume.content }}:/var/lib/ghost/content

    {{-- --}}
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

    {{-- --}}
    environment:
      MYSQL_ROOT_PASSWORD: {{ secret.MYSQL_ROOT_PASSWORD }}
      MYSQL_DATABASE: ghost
      MYSQL_USER: ghost
      MYSQL_PASSWORD: {{ secret.GHOST_DB_PASSWORD }}

    volumes:
      - {{ volume.db }}:/var/lib/mysql

volumes:
  {{ volume.content }}:
    external: true
  {{ volume.db }}:
    external: true
