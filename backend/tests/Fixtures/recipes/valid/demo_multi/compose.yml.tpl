{{-- Header --}}
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
    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"
    environment:
      APP_URL: {{ url }}
      PASSWORD: "{{ secret.PASSWORD|base64 }}"
    volumes:
      - {{ volume.data }}:/data
      - {{ site_root }}/app/config:/app/config
  db:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data
volumes:
  {{ volume.db }}:
    external: true
  {{ volume.data }}:
    external: true
