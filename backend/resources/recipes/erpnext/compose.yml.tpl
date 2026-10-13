{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     ERPNext v16.50.0 from the official frappe_docker pwd.yml: nginx frontend, gunicorn backend,
     websocket, two queue workers, scheduler, MariaDB and two Redis (RC-33). --}}
name: {{ project }}

services:
  frontend:
    image: {{ image.erpnext }}
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

    command: ["nginx-entrypoint.sh"]

    environment:
      BACKEND: backend:8000
      SOCKETIO: websocket:9000
      FRAPPE_SITE_NAME_HEADER: frontend
      UPSTREAM_REAL_IP_ADDRESS: 127.0.0.1
      UPSTREAM_REAL_IP_HEADER: X-Forwarded-For
      UPSTREAM_REAL_IP_RECURSIVE: "off"
      PROXY_READ_TIMEOUT: "120"
      CLIENT_MAX_BODY_SIZE: 50m

    volumes:
      - {{ site_root }}/templates/nginx:/templates/nginx:ro
      - {{ volume.sites }}:/home/frappe/frappe-bench/sites
      - {{ volume.logs }}:/home/frappe/frappe-bench/logs

    depends_on:
      - websocket
      - backend

    healthcheck:
      test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:8080/api/method/ping"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 1200s

  backend:
    image: {{ image.erpnext }}
    restart: unless-stopped
    mem_limit: 2g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["bash", "-c", "set -e; wait-for-it -t 120 db:3306; wait-for-it -t 120 redis-cache:6379; wait-for-it -t 120 redis-queue:6379; ls -1 apps > sites/apps.txt; bench set-config -g db_host db; bench set-config -gp db_port 3306; bench set-config -g redis_cache redis://redis-cache:6379; bench set-config -g redis_queue redis://redis-queue:6379; bench set-config -g redis_socketio redis://redis-queue:6379; bench set-config -gp socketio_port 9000; if [ ! -f sites/frontend/.panel-site-ready ]; then bench new-site frontend --force --mariadb-user-host-login-scope=% --db-root-username root --db-root-password $$DB_ROOT_PASSWORD --admin-password $$ADMIN_PASSWORD --install-app erpnext --set-default; touch sites/frontend/.panel-site-ready; fi; exec start.sh"]

    environment:
      DB_ROOT_PASSWORD: "{{ secret.DB_ROOT_PASSWORD }}"
      ADMIN_PASSWORD: "{{ secret.ADMIN_PASSWORD }}"

    volumes:
      - {{ volume.sites }}:/home/frappe/frappe-bench/sites
      - {{ volume.logs }}:/home/frappe/frappe-bench/logs

    depends_on:
      db:
        condition: service_healthy
      redis-cache:
        condition: service_started
      redis-queue:
        condition: service_started

    healthcheck:
      test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "-H", "X-Frappe-Site-Name: frontend", "http://127.0.0.1:8000/api/method/ping"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 1200s

  websocket:
    image: {{ image.erpnext }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["bash", "-c", "until [ -f sites/frontend/.panel-site-ready ]; do sleep 5; done; exec node /home/frappe/frappe-bench/apps/frappe/socketio.js"]

    environment:
      FRAPPE_REDIS_CACHE: redis://redis-cache:6379
      FRAPPE_REDIS_QUEUE: redis://redis-queue:6379

    volumes:
      - {{ volume.sites }}:/home/frappe/frappe-bench/sites
      - {{ volume.logs }}:/home/frappe/frappe-bench/logs

  queue-long:
    image: {{ image.erpnext }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["bash", "-c", "until [ -f sites/frontend/.panel-site-ready ]; do sleep 5; done; exec bench worker --queue long,default,short"]

    environment:
      FRAPPE_REDIS_CACHE: redis://redis-cache:6379
      FRAPPE_REDIS_QUEUE: redis://redis-queue:6379

    volumes:
      - {{ volume.sites }}:/home/frappe/frappe-bench/sites
      - {{ volume.logs }}:/home/frappe/frappe-bench/logs

  queue-short:
    image: {{ image.erpnext }}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["bash", "-c", "until [ -f sites/frontend/.panel-site-ready ]; do sleep 5; done; exec bench worker --queue short,default"]

    environment:
      FRAPPE_REDIS_CACHE: redis://redis-cache:6379
      FRAPPE_REDIS_QUEUE: redis://redis-queue:6379

    volumes:
      - {{ volume.sites }}:/home/frappe/frappe-bench/sites
      - {{ volume.logs }}:/home/frappe/frappe-bench/logs

  scheduler:
    image: {{ image.erpnext }}
    restart: unless-stopped
    mem_limit: 512m
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["bash", "-c", "until [ -f sites/frontend/.panel-site-ready ]; do sleep 5; done; exec bench schedule"]

    volumes:
      - {{ volume.sites }}:/home/frappe/frappe-bench/sites
      - {{ volume.logs }}:/home/frappe/frappe-bench/logs

  db:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_unicode_ci", "--skip-character-set-client-handshake"]

    environment:
      MARIADB_ROOT_PASSWORD: "{{ secret.DB_ROOT_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/mysql

    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 24
      start_period: 30s

  redis-cache:
    image: {{ image.redis }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

  redis-queue:
    image: {{ image.redis }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    volumes:
      - {{ volume.redis-queue }}:/data

volumes:
  {{ volume.sites }}:
    external: true
  {{ volume.logs }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis-queue }}:
    external: true
