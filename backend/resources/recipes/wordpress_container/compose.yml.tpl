{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     WordPress: generated from the golden fixture by template-from-golden.php (RC-04). --}}
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
      WORDPRESS_DB_HOST: "db"
      WORDPRESS_DB_NAME: "wordpress"
      WORDPRESS_DB_USER: "wordpress"
      WORDPRESS_SITE_URL: "{{ url }}"
      WORDPRESS_DB_PASSWORD: "{{ secret.DATABASE_PASSWORD }}"
      {{-- --}}
      WORDPRESS_CONFIG_EXTRA: |
        if (isset($$_SERVER['HTTP_X_FORWARDED_PROTO']) && $$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $$_SERVER['HTTPS'] = 'on';
        }
        if (getenv('WORDPRESS_SITE_URL')) {
            define('WP_HOME', getenv('WORDPRESS_SITE_URL'));
            define('WP_SITEURL', getenv('WORDPRESS_SITE_URL'));
        }

    volumes:
      - {{ volume.app }}:/var/www/html

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
      MARIADB_DATABASE: wordpress
      MARIADB_USER: wordpress
      MARIADB_PASSWORD: "{{ secret.DATABASE_PASSWORD }}"
      MARIADB_ROOT_PASSWORD: "{{ secret.MARIADB_ROOT_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/mysql

volumes:
  {{ volume.app }}:
    external: true
  {{ volume.db }}:
    external: true
