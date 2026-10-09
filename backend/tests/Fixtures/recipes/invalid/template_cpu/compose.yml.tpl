{{-- Header --}}
name: {{ project }}
services:
  app:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: {{ memory_limit }}
    cpus: {{ cpu_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"
    environment:
      APP_URL: {{ url }}
      PASSWORD: "{{ secret.PASSWORD }}"
    volumes:
      - {{ volume.data }}:/data
volumes:
  {{ volume.data }}:
    external: true
