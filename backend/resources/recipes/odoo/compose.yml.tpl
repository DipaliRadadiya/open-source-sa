{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Odoo Community: threaded application and private PostgreSQL (RC-16). --}}
name: {{ project }}

services:
  odoo:
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
      HOST: db
      USER: odoo
      PASSWORD: "{{ secret.DATABASE_PASSWORD }}"
      MASTER_PASSWORD: "{{ secret.MASTER_PASSWORD }}"
      ODOO_RC: /var/lib/odoo/odoo.conf

    command:
      - /bin/sh
      - -ec
      - |
        python3 - <<'PY'
        import configparser
        import os
        import tempfile

        path = os.environ['ODOO_RC']
        config = configparser.ConfigParser(interpolation=None)
        source = path if os.path.exists(path) else '/etc/odoo/odoo.conf'
        with open(source, encoding='utf-8') as stream:
            config.read_file(stream)
        config.set('options', 'admin_passwd', os.environ['MASTER_PASSWORD'])
        fd, temporary = tempfile.mkstemp(prefix='.odoo-conf-', dir=os.path.dirname(path))
        try:
            with os.fdopen(fd, 'w', encoding='utf-8') as stream:
                config.write(stream)
            os.replace(temporary, path)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
        PY
        exec /entrypoint.sh odoo -c "$$ODOO_RC" --proxy-mode --without-demo --workers=0 --http-port=8069

    volumes:
      - {{ volume.data }}:/var/lib/odoo

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
      POSTGRES_DB: postgres
      POSTGRES_USER: odoo
      POSTGRES_PASSWORD: "{{ secret.DATABASE_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]
      interval: 5s
      timeout: 5s
      retries: 12

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.db }}:
    external: true
