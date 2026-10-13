{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     PrestaShop 9 (Apache image) with its private MariaDB, installed by the image's PS_INSTALL_AUTO (RC-37). --}}
name: {{ project }}

services:
  prestashop:
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

    command:
      - sh
      - -c
      - 'echo SetEnvIf X-Forwarded-Proto https HTTPS=on > /etc/apache2/conf-enabled/zz-forwarded-https.conf; if [ -f /var/www/html/app/config/parameters.php ] && [ -f /ps-init/sync-domain.php ]; then runuser -u www-data -- php /ps-init/sync-domain.php || true; fi; exec /tmp/docker_run.sh'

    environment:
      PS_INSTALL_AUTO: "1"
      PS_DOMAIN: "{{ domain }}"
      PS_SHOP_URL: "{{ url }}"
      PS_ENABLE_SSL: "1"
      PS_FOLDER_ADMIN: shopadmin
      PS_FOLDER_INSTALL: install
      PS_LANGUAGE: en
      PS_COUNTRY: GB
      ADMIN_MAIL: "{{ input.admin_email }}"
      ADMIN_PASSWD: "{{ secret.ADMIN_PASSWORD }}"
      DB_SERVER: db
      DB_PORT: "3306"
      DB_NAME: prestashop
      DB_USER: prestashop
      DB_PASSWD: "{{ secret.DB_PASSWORD }}"
      DB_PREFIX: ps_

    volumes:
      - {{ volume.html }}:/var/www/html
      - {{ site_root }}/ps-init:/ps-init:ro

    depends_on:
      db:
        condition: service_healthy

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
      MYSQL_DATABASE: prestashop
      MYSQL_USER: prestashop
      MYSQL_PASSWORD: "{{ secret.DB_PASSWORD }}"
      MYSQL_ROOT_PASSWORD: "{{ secret.DB_ROOT_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/mysql

    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 24

volumes:
  {{ volume.html }}:
    external: true
  {{ volume.db }}:
    external: true
